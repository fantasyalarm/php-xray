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
        $this->app->singleton('xray', function () {
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
            $samplingRuleRepository = new \Pkerrigan\Xray\SamplingRule\AwsSdkSamplingRuleRepository($xrayClient,$fallbackSamplingRule);
            //TODO: CACHING
            //$cachedSamplingRuleRepository = new CachedSamplingRuleRepository($samplingRuleRepository, $psrCacheImplementation);
            return new \Pkerrigan\Xray\TraceService($samplingRuleRepository, new \Pkerrigan\Xray\Submission\DaemonSegmentSubmitter());
        });
    }
}