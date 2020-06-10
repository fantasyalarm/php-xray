<?php


namespace Pkerrigan\Xray;
use Illuminate\Support\ServiceProvider;
use Pkerrigan\Xray\SamplingRule\CachedSamplingRuleRepository;
use Pkerrigan\Xray\SamplingRule\SamplingRuleBuilder;


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
                    ->setFixedRate($this['config']->get('app.trace.samples'))
                    ->setHttpMethod($_SERVER['REQUEST_METHOD'] ?? 'cmd')
                    ->setHost($this['config']->get('app.host'))
                    ->setServiceName($this['config']->get('app.name'))
                    ->setServiceType('*')
                    ->setUrlPath('')
                    ->build();
                $xrayClient = new \Aws\XRay\XRayClient([
                    'version' => 'latest',
                    'region' => 'us-east-1'
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
                    ->setName($this['config']->get('app.name'))
                    ->setUrl($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_FILENAME'])
                    ->setMethod($_SERVER['REQUEST_METHOD'] ?? 'cmd')
                    ->begin($this['config']->get('app.trace.samples'));
            }
            return $trace;
        });
    }
}