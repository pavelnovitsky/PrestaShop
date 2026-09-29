// Import utils
import testContext from '@utils/testContext';

// Import common tests
import setFeatureFlag from '@commonTests/BO/advancedParameters/newFeatures';

import {expect} from 'chai';
import {
  type APIRequestContext,
  boCspPage,
  boDashboardPage,
  boFeatureFlagPage,
  boLoginPage,
  boSecurityPage,
  type BrowserContext,
  type Page,
  utilsPlaywright,
} from '@prestashop-core/ui-testing';

const baseContext: string = 'functional_BO_advancedParameters_security_cspReportCollector';

/*
Pre-condition:
- Enable the 'csp' feature flag
Scenario:
- POST a CSP violation report to the public storefront collector
- Open the Content Security Policy page in the BO and check the reported source is in the log grid
Post-condition:
- Disable the 'csp' feature flag
 */
describe('CSP : The storefront collector records a reported violation into the log grid', async () => {
  let browserContext: BrowserContext;
  let page: Page;
  let foApiContext: APIRequestContext;

  // A source a browser would report as blocked; the collector stores it reduced to its origin.
  const blockedOrigin: string = 'https://ui-test-collector.example.com';
  const reportBody: string = JSON.stringify({
    'csp-report': {
      'effective-directive': 'script-src',
      'blocked-uri': `${blockedOrigin}/widget.js`,
      'document-uri': global.FO.URL,
    },
  });

  // Pre-condition: enable the CSP feature flag (beta)
  setFeatureFlag(boFeatureFlagPage.featureFlagCsp, true, `${baseContext}_enableCsp`);

  before(async function () {
    browserContext = await utilsPlaywright.createBrowserContext(this.browser);
    page = await utilsPlaywright.newTab(browserContext);
    // A request context bound to the storefront, so the report POST carries the shop's Host.
    foApiContext = await utilsPlaywright.createAPIContext(global.FO.URL);
  });

  after(async () => {
    await utilsPlaywright.closeBrowserContext(browserContext);
  });

  describe('Report a violation and curate it in the back office', async () => {
    it('should login in BO', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'loginBO', baseContext);

      await boLoginPage.goTo(page, global.BO.URL);
      await boLoginPage.successLogin(page, global.BO.EMAIL, global.BO.PASSWD);

      const pageTitle = await boDashboardPage.getPageTitle(page);
      expect(pageTitle).to.contains(boDashboardPage.pageTitle);
    });

    it('should POST a CSP violation report to the storefront collector', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'postReport', baseContext);

      const response = await foApiContext.post('index.php?controller=cspreport', {
        headers: {'content-type': 'application/csp-report'},
        data: reportBody,
      });
      expect(response.status()).to.eq(204);
    });

    it('should go to the \'Content Security Policy\' page', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'goToCspPage', baseContext);

      await boDashboardPage.goToSubMenu(
        page,
        boDashboardPage.advancedParametersLink,
        boDashboardPage.securityLink,
      );
      await boSecurityPage.goToCspPage(page);

      const pageTitle = await boCspPage.getPageTitle(page);
      expect(pageTitle).to.contains(boCspPage.pageTitle);
    });

    it('should find the reported source in the log grid, normalized to its origin', async function () {
      await testContext.addContextItem(this, 'testIdentifier', 'checkGrid', baseContext);

      const row = await boCspPage.getNthRowBySource(page, blockedOrigin);
      expect(row, 'The reported source was not found in the CSP log grid').to.not.eq(null);

      const directive = await boCspPage.getTextColumn(page, 'directive', row as number);
      expect(directive).to.contains('script-src');
    });
  });

  // Post-condition: disable the CSP feature flag
  setFeatureFlag(boFeatureFlagPage.featureFlagCsp, false, `${baseContext}_disableCsp`);
});
