<?php


namespace Pkerrigan\Xray;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Pkerrigan\Xray\SamplingRule\CachedSamplingRuleRepository;
use Pkerrigan\Xray\SamplingRule\SamplingRuleBuilder;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


class XRayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('xray.service', function ($app) {
                $fallbackSamplingRule = (new SamplingRuleBuilder())
                    ->setFixedRate($app['config']->get('app.trace.samples'))
                    ->setHttpMethod('*')
                    ->setHost('*')
                    ->setServiceName('*')
                    ->setServiceType('*')
                    ->setUrlPath('*')
                    ->build();
            $handlerStack = \GuzzleHttp\HandlerStack::create();
            $handlerStack->push(Middleware::mapRequest(function (RequestInterface $request) {
                Container::getInstance()->make('xray.trace')->addSubSegment( (new HttpSegment())
                    ->setName('AWS CLI Call')
                    ->setUrl($request->getRequestTarget())
                    ->setMethod($request->getMethod())
                    ->begin());
                return $request;
            }));
            $handlerStack->push(Middleware::mapResponse(function (ResponseInterface $response) {
                Container::getInstance()->make('xray.trace')
                    ->setResponseCode($response->getStatusCode())
                    ->end();
                return $response;
            }));
            $client =new Client(['handler' => $handlerStack,'verify'=>false]);
            $handler = new GuzzleHandler($client);

            $xrayClient = new \Aws\XRay\XRayClient([
                    'version' => 'latest',
                    'region' => 'us-east-1',
                    'http_handler' => $handler
                ]);

                $samplingRuleRepository = new \Pkerrigan\Xray\SamplingRule\AwsSdkSamplingRuleRepository($xrayClient, $fallbackSamplingRule,!$app['config']->get('app.trace.enabled'));
                //TODO: CACHING
                //$cachedSamplingRuleRepository = new CachedSamplingRuleRepository($samplingRuleRepository, $psrCacheImplementation);
                return new \Pkerrigan\Xray\TraceService($samplingRuleRepository, new \Pkerrigan\Xray\Submission\DaemonSegmentSubmitter());
        });
        $this->app->singleton('xray.trace', function ($app) {
            $trace =  new Trace();
            if ($app['config']->get('app.trace.enabled')) {
                $trace
                    ->setTraceHeader($_SERVER['HTTP_X_AMZN_TRACE_ID'] ?? null)
                    ->setName($app['config']->get('app.name'))
                    ->setUrl($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_FILENAME'])
                    ->setMethod($_SERVER['REQUEST_METHOD'] ?? 'cmd')
                    ->begin($app['config']->get('app.trace.samples'));
            }
            return $trace;
        });
    }
}