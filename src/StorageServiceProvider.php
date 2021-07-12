<?php
namespace QT\Filesystem;

use OSS\OssClient;
use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['filesystem']->extend('aliyun', function ($config) {
            return $this->app->make(AliyunOss::class, ['config' => $config]);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(AliyunOss::class, function ($app, array $config = []) {
            if (!class_exists(OssClient::class)) {
                throw new InvalidArgumentException("依赖\"aliyuncs/oss-sdk-php\",请引入后再试");
            }
    
            if (empty($config['access_key_id'])) {
                throw new InvalidArgumentException('阿里云oss app_id 不能为空');
            }
    
            if (empty($config['access_key_secret'])) {
                throw new InvalidArgumentException('阿里云oss app_secret 不能为空');
            }
    
            if (empty($config['bucket'])) {
                throw new InvalidArgumentException('bucket不允许为空');
            }
    
            if (empty($config['end_point'])) {
                // 默认使用深圳节点
                $config['end_point'] = 'oss-cn-shenzhen.aliyuncs.com';
            }
    
            $client = new OssClient(
                $config['access_key_id'],
                $config['access_key_secret'],
                $config['end_point']
            );
    
            return new AliyunOss($client, $config['bucket'], $config);
        });
    }
}
