<?php
/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Adapter\Csp;

use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Adapter\Shop\Context;
use PrestaShop\PrestaShop\Core\Configuration\AbstractMultistoreConfiguration;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use PrestaShop\PrestaShop\Core\Feature\FeatureInterface;
use PrestaShopBundle\Entity\Repository\CspLogRepository;
use PrestaShopBundle\Entity\Repository\CspRuleRepository;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Loads and saves the Content Security Policy settings block
 * (Advanced parameters > Security > Content Security Policy).
 */
final class CspConfiguration extends AbstractMultistoreConfiguration
{
    private const CONFIGURATION_FIELDS = ['enabled', 'report_only'];

    public function __construct(
        Configuration $configuration,
        Context $shopContext,
        FeatureInterface $multistoreFeature,
        private readonly CspLogRepository $cspLogRepository,
        private readonly CspRuleRepository $cspRuleRepository,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($configuration, $shopContext, $multistoreFeature);
    }

    public function getConfiguration()
    {
        $shopConstraint = $this->getShopConstraint();

        return [
            'enabled' => (bool) $this->configuration->get('PS_CSP_ENABLED', false, $shopConstraint),
            'report_only' => (bool) $this->configuration->get('PS_CSP_REPORT_ONLY', true, $shopConstraint),
        ];
    }

    public function updateConfiguration(array $configuration)
    {
        // validateConfiguration() throws on invalid input, so this branch is effectively unreachable (kept for parity).
        if (!$this->validateConfiguration($configuration)) {
            return [];
        }

        $shopConstraint = $this->getShopConstraint();

        // Block the report-only -> enforcing switch until the shop has a baseline;
        // enforcing an empty policy would break the storefront.
        if ($this->isTurningOnEnforcement($configuration)
            && !$this->isAlreadyEnforcing($shopConstraint)
            && !$this->hasBaseline($shopConstraint)
        ) {
            return [
                $this->translator->trans(
                    'Enforce the Content Security Policy only after building an allow-list. Either this shop has not recorded a violation or added an allowed source yet, or you are editing all shops at once (enforce each shop from its own page after curating it). Keep "Report-only mode" on until the log reflects your storefront or you have added the sources it needs.',
                    [],
                    'Admin.Advparameters.Notification'
                ),
            ];
        }

        $this->updateConfigurationValue('PS_CSP_ENABLED', 'enabled', $configuration, $shopConstraint);
        $this->updateConfigurationValue('PS_CSP_REPORT_ONLY', 'report_only', $configuration, $shopConstraint);

        return [];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function isTurningOnEnforcement(array $configuration): bool
    {
        return array_key_exists('enabled', $configuration) && (bool) $configuration['enabled']
            && array_key_exists('report_only', $configuration) && !(bool) $configuration['report_only'];
    }

    private function isAlreadyEnforcing(?ShopConstraint $shopConstraint): bool
    {
        return (bool) $this->configuration->get('PS_CSP_ENABLED', false, $shopConstraint)
            && !(bool) $this->configuration->get('PS_CSP_REPORT_ONLY', true, $shopConstraint);
    }

    private function hasBaseline(?ShopConstraint $shopConstraint): bool
    {
        // An all-shop/group scope has no single shop, so report no baseline and refuse the blanket enforce transition.
        $shopId = $shopConstraint?->getShopId()?->getValue();
        if (null === $shopId) {
            return false;
        }

        return $this->cspLogRepository->countByShop($shopId) > 0
            || [] !== $this->cspRuleRepository->getRulesByShop($shopId);
    }

    protected function buildResolver(): OptionsResolver
    {
        return (new OptionsResolver())
            ->setDefined(self::CONFIGURATION_FIELDS)
            ->setAllowedTypes('enabled', 'bool')
            ->setAllowedTypes('report_only', 'bool');
    }
}
