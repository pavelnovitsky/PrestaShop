<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

use PrestaShop\PrestaShop\Adapter\Csp\CspFeatureChecker;
use PrestaShop\PrestaShop\Adapter\Csp\CspViolationRecorder;
use PrestaShop\PrestaShop\Core\Csp\CspReportParser;

/** Public, unauthenticated endpoint that receives browser CSP violation reports: read the body, record, answer 204. */
class CspReportControllerCore extends FrontController
{
    private const MAX_BODY_SIZE = 65536;

    /** Keep recording during maintenance, which is often exactly when a merchant tests enforcement. */
    protected function displayMaintenancePage()
    {
    }

    public function postProcess()
    {
        $this->collectReports();

        // No body, no template: the browser ignores the response, and a 204 keeps the endpoint cheap.
        http_response_code(204);
        exit;
    }

    private function collectReports(): void
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            return;
        }

        try {
            $shopId = (int) $this->context->shop->id;

            /** @var CspFeatureChecker $featureChecker */
            $featureChecker = $this->get(CspFeatureChecker::class);
            if (!$featureChecker->isEnabledForShop($shopId)) {
                return;
            }

            // Read one byte past the cap so an oversized body is rejected without holding the whole payload in memory.
            $body = file_get_contents('php://input', false, null, 0, self::MAX_BODY_SIZE + 1);
            if (!is_string($body) || $body === '' || strlen($body) > self::MAX_BODY_SIZE) {
                return;
            }

            $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
            $reports = CspReportParser::parse($contentType, $body);
            if ($reports === []) {
                return;
            }

            /** @var CspViolationRecorder $recorder */
            $recorder = $this->get(CspViolationRecorder::class);
            $anyInserted = false;
            foreach ($reports as $report) {
                $anyInserted = $recorder->record($shopId, $report['directive'], $report['blockedUri'], $report['documentUri'], false) || $anyInserted;
            }

            // Enforce the row cap once per batch, and only when a new row was inserted
            // (bumped counters can't exceed the cap).
            if ($anyInserted) {
                $recorder->enforceRowCap($shopId);
            }
        } catch (Throwable $e) {
            // This public endpoint must never 500 on a DB hiccup: skip recording, still answer 204, but log it.
            try {
                PrestaShopLogger::addLog('CSP report not recorded: ' . $e->getMessage(), 2, null, 'CspReport');
            } catch (Throwable) {
                // The DB-backed logger can fail the same way; fall back to the error log.
                error_log('CSP report not recorded: ' . $e->getMessage());
            }
        }
    }
}
