<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Adapter\Csp;

use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Adapter\Csp\CspConfiguration;
use PrestaShop\PrestaShop\Adapter\Shop\Context;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\Feature\FeatureInterface;
use PrestaShopBundle\Entity\Repository\CspLogRepository;
use PrestaShopBundle\Entity\Repository\CspRuleRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class CspConfigurationTest extends TestCase
{
    private const SHOP_ID = 1;

    public function testItRefusesToEnforceOnAShopWithNoBaseline(): void
    {
        $configuration = $this->createMock(Configuration::class);
        // The dangerous save must never reach the configuration store.
        $configuration->expects($this->never())->method('set');

        $errors = $this->cspConfiguration($configuration, reportCount: 0)
            ->updateConfiguration(['enabled' => true, 'report_only' => false]);

        $this->assertNotEmpty($errors, 'Enforcing with no baseline (no reports, no rules) must be rejected with an error');
    }

    public function testItAllowsEnforcementOnceReportsHaveBeenCollected(): void
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->expects($this->exactly(2))->method('set');

        $errors = $this->cspConfiguration($configuration, reportCount: 5)
            ->updateConfiguration(['enabled' => true, 'report_only' => false]);

        $this->assertSame([], $errors);
    }

    public function testItAllowsEnablingReportOnlyModeWithNoReports(): void
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->expects($this->exactly(2))->method('set');

        // The safe path (report-only on) must not be blocked even with an empty log.
        $errors = $this->cspConfiguration($configuration, reportCount: 0)
            ->updateConfiguration(['enabled' => true, 'report_only' => true]);

        $this->assertSame([], $errors);
    }

    public function testItLetsAnAlreadyEnforcingShopSaveEvenWithAnEmptyLog(): void
    {
        $configuration = $this->createMock(Configuration::class);
        // The shop is already enforcing (enabled on, report-only off) — e.g. it enforced, then the
        // merchant cleared the log. Re-saving the same enforcing state must not be rejected.
        $configuration->method('get')->willReturnCallback(
            fn (string $key, $default = null) => match ($key) {
                'PS_CSP_ENABLED' => '1',
                'PS_CSP_REPORT_ONLY' => '0',
                default => $default,
            }
        );
        $configuration->expects($this->exactly(2))->method('set');

        $errors = $this->cspConfiguration($configuration, reportCount: 0)
            ->updateConfiguration(['enabled' => true, 'report_only' => false]);

        $this->assertSame([], $errors);
    }

    public function testItLetsAShopEnforceWhenItHasCuratedRulesButAnEmptyLog(): void
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->expects($this->exactly(2))->method('set');

        // Log is empty (e.g. cleared after curating, or sources added manually), but an allow-list
        // exists — enforcement must not be blocked.
        $errors = $this->cspConfiguration($configuration, reportCount: 0, ruleCount: 3)
            ->updateConfiguration(['enabled' => true, 'report_only' => false]);

        $this->assertSame([], $errors);
    }

    public function testItRefusesToTurnOnEnforcementInAllShopContext(): void
    {
        $configuration = $this->createMock(Configuration::class);
        // The dangerous all-shop enforce transition must never reach the store.
        $configuration->expects($this->never())->method('set');

        $shopContext = $this->createMock(Context::class);
        $shopContext->method('getShopConstraint')->willReturn(ShopConstraint::allShops());

        $ruleRepository = $this->createMock(CspRuleRepository::class);
        $ruleRepository->method('getRulesByShop')->willReturn([]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('error message');

        $config = new CspConfiguration(
            $configuration,
            $shopContext,
            $this->createMock(FeatureInterface::class),
            $this->createMock(CspLogRepository::class),
            $ruleRepository,
            $translator
        );

        $errors = $config->updateConfiguration(['enabled' => true, 'report_only' => false]);

        $this->assertNotEmpty($errors, 'Enforcing across all shops at once must be refused');
    }

    public function testItAllowsSavingReportOnlyModeInAllShopContext(): void
    {
        $configuration = $this->createMock(Configuration::class);
        // The baseline guard only fences the enforce transition; the safe report-only save across all
        // shops must still go through to the store.
        $configuration->expects($this->exactly(2))->method('set');

        $shopContext = $this->createMock(Context::class);
        $shopContext->method('getShopConstraint')->willReturn(ShopConstraint::allShops());

        $config = new CspConfiguration(
            $configuration,
            $shopContext,
            $this->createMock(FeatureInterface::class),
            $this->createMock(CspLogRepository::class),
            $this->createMock(CspRuleRepository::class),
            $this->createMock(TranslatorInterface::class)
        );

        $errors = $config->updateConfiguration(['enabled' => true, 'report_only' => true]);

        $this->assertSame([], $errors);
    }

    private function cspConfiguration(Configuration $configuration, int $reportCount, int $ruleCount = 0): CspConfiguration
    {
        $shopContext = $this->createMock(Context::class);
        $shopContext->method('isAllShopContext')->willReturn(false);
        $shopContext->method('getShopConstraint')->willReturn(ShopConstraint::shop(self::SHOP_ID));

        $multistoreFeature = $this->createMock(FeatureInterface::class);
        $multistoreFeature->method('isUsed')->willReturn(false);

        $logRepository = $this->createMock(CspLogRepository::class);
        $logRepository->method('countByShop')->with(self::SHOP_ID)->willReturn($reportCount);

        $ruleRepository = $this->createMock(CspRuleRepository::class);
        $ruleRepository->method('getRulesByShop')->with(self::SHOP_ID)->willReturn(array_fill(0, $ruleCount, []));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('error message');

        return new CspConfiguration($configuration, $shopContext, $multistoreFeature, $logRepository, $ruleRepository, $translator);
    }
}
