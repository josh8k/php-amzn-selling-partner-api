<?php

namespace Jasara\AmznSPA\Tests\Unit\Resources;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Jasara\AmznSPA\AmznSPA;
use Jasara\AmznSPA\Constants\MarketplaceData;
use Jasara\AmznSPA\Exceptions\AmznSPAException;
use Jasara\AmznSPA\Exceptions\AuthenticationException;
use Jasara\AmznSPA\Tests\Unit\UnitTestCase;

/**
 * @coversDefaultClass \Jasara\AmznSPA\Resources\OAuthResource
 */
class OAuthResourceTest extends UnitTestCase
{
    /**
     * @dataProvider marketplaces
     * @covers ::getAuthUrl()
     * @covers ::getBaseUrlFromMarketplace()
     */
    public function testAuthUrlGenerated(array $marketplace_data)
    {
        $amzn = new AmznSPA($this->setupMinimalConfig($marketplace_data['marketplace_id']));
        $url = $amzn->oauth->getAuthUrl();

        $this->assertEquals($marketplace_data['base_url'] . '/apps/authorize/consent', $url);
    }

    /**
     * @dataProvider marketplaces
     * @covers ::getAuthUrl()
     * @covers ::getBaseUrlFromMarketplace()
     */
    public function testAuthUrlGeneratedWithStateAndRedirectUrl(array $marketplace_data)
    {
        $state = Str::random();
        $redirect_url = 'https://test.com/' . $state;

        $amzn = new AmznSPA($this->setupMinimalConfig($marketplace_data['marketplace_id']));
        $url = $amzn->oauth->getAuthUrl($redirect_url, $state);

        $this->assertEquals($marketplace_data['base_url'] . '/apps/authorize/consent?redirect_url=https%3A%2F%2Ftest.com%2F' . $state . '&state=' . $state, $url);
    }

    /**
     * @covers ::isRedirectValid()
     * @covers ::getTokensFromRedirect()
     */
    public function testStateDoesNotMatch()
    {
        $this->expectException(AmznSPAException::class);
        $this->expectExceptionMessage('State returned from Amazon does not match the original state');

        $amzn = new AmznSPA($this->setupMinimalConfig());
        $amzn->oauth->getTokensFromRedirect(Str::random(), [
            'state' => Str::random(),
            'spapi_oauth_code' => Str::random(),
        ]);
    }

    /**
     * @covers ::__construct()
     * @covers ::getTokensFromRedirect()
     * @covers ::callGetTokens()
     * @covers ::isRedirectValid()
     */
    public function testGetTokensFromRedirect()
    {
        $state = Str::random();
        $spapi_oauth_code = Str::random();

        list($config, $http) = $this->setupConfigWithFakeHttp('oauth/get-tokens');

        $amzn = new AmznSPA($config);
        $tokens = $amzn->oauth->getTokensFromRedirect($state, [
            'state' => $state,
            'spapi_oauth_code' => $spapi_oauth_code,
        ]);

        $this->assertEquals('Atza|IQEBLjAsAexampleHpi0U-Dme37rR6CuUpSR', Arr::get($tokens, 'access_token'));
        $this->assertEquals('bearer', Arr::get($tokens, 'token_type'));
        $this->assertEquals(3600, Arr::get($tokens, 'expires_in'));
        $this->assertEquals('Atzr|IQEBLzAtAhexamplewVz2Nn6f2y-tpJX2DeX', Arr::get($tokens, 'refresh_token'));

        $http->assertSent(function (Request $request) use ($spapi_oauth_code, $config) {
            $this->assertEquals('authorization_code', Arr::get($request, 'grant_type'));
            $this->assertEquals($spapi_oauth_code, Arr::get($request, 'code'));
            $this->assertEquals($config->redirect_url, Arr::get($request, 'redirect_uri'));
            $this->assertEquals($config->lwa_client_id, Arr::get($request, 'client_id'));
            $this->assertEquals($config->lwa_client_secret, Arr::get($request, 'client_secret'));

            return true;
        });
    }

    /**
     * @covers ::callGetTokens
     */
    public function testGetTokensFromRedirectError()
    {
        $this->expectException(AuthenticationException::class);

        $state = Str::random();
        $spapi_oauth_code = Str::random();

        list($config) = $this->setupConfigWithFakeHttp('errors/invalid-client', 401);

        $amzn = new AmznSPA($config);
        $amzn->oauth->getTokensFromRedirect($state, [
            'state' => $state,
            'spapi_oauth_code' => $spapi_oauth_code,
        ]);
    }

    public function marketplaces(): array
    {
        $marketplaces = array_map(function ($key, $elem) {
            return [$key => array_merge($elem, [
                'marketplace_id' => $key,
            ])];
        }, array_keys(MarketplaceData::allMarketplaces()), MarketplaceData::allMarketplaces());

        return $marketplaces;
    }
}
