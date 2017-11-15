<?php

namespace scheduler;

use common\Consts;
use common\LoadClass;
use exceptionHandler\SchedulerException;
use ZPHP\Core\Config as ZConfig;
use sdk\TcpClient;
use ZPHP\ZPHP;

/**
 * Created by PhpStorm.
 * User: shenzhe
 * Date: 2016/11/17
 * Time: 15:29
 * @desc 服务调度器
 */
class Scheduler
{

    /**
     * @var ISelector
     */
    private static $selector = null;

    /**
     * @param $serviceName
     * @return array [$ip, $port, type]
     * @throws SchedulerException
     * @throws \Exception
     * @desc 根据服务名获名一个可用的ip:port
     */
    public static function getService($serviceName)
    {

        if (Consts::REGISTER_SERVER_NAME == $serviceName) {
            return [
                ZConfig::getField('socket', 'host'),
                ZConfig::getField('socket', 'port'),
                ZConfig::getField('socket', 'server_type'),
            ];
        }

        $soaConfig = ZConfig::get('soa');
        if (empty($soaConfig)) {
            throw new SchedulerException('soa config empty');
        }

        $serverList = self::getList($serviceName, $soaConfig);
        if (!self::$selector) {
            self::$selector = Factory::getInstance(ZConfig::getField('project', 'selector', 'Vote'));
        }
        $current = self::getOne($serviceName, $serverList);
        return [
            $current['ip'],
            $current['port'],
            $current['serverType'],
        ];

    }

    /**
     * @param $serviceName
     * @param $serverList
     * @return array
     */
    public static function getOne($serviceName, $serverList)
    {
        return self::$selector->getOne($serviceName, $serverList);
    }

    /**
     * @param $serviceName
     * @param $soaConfig
     * @return mixed|null|array
     * @throws SchedulerException
     * @throws \Exception
     */
    public static function getList($serviceName, $soaConfig)
    {
        if (ZConfig::get('project_name') === Consts::REGISTER_SERVER_NAME) {
            $serverList = LoadClass::getService('ServiceList')->getServiceList($serviceName);
            if (!empty($serverList)) {
                $serverList = json_decode(json_encode($serverList), true);
            }
            return $serverList;
        }
        $serverList = ZConfig::get($serviceName);
        if (empty($serverList)) {
            $serverList = self::getListForRpc($serviceName, $soaConfig);
        }

        if (empty($serverList)) {
            throw new SchedulerException($serviceName . " serverlist empty", -1);
        }
        return $serverList;
    }

    /**
     * @param $serviceName
     * @param null $soaConfig
     * @return array
     * @throws SchedulerException
     * @throws \Exception
     */
    public static function getListForRpc($serviceName, $soaConfig = null)
    {
        if (!$soaConfig) {
            $soaConfig = ZConfig::get('soa');
            if (empty($soaConfig)) {
                throw new SchedulerException('soa config empty');
            }
        }
        $rpcClient = new TcpClient($soaConfig['ip'], $soaConfig['port'], empty($soaConfig['timeOut']) ? 0 : $soaConfig['timeOut']);
        $isDot = Consts::MONITOR_SERVER_NAME == $serviceName ? 0 : 1;
        $data = $rpcClient->setApi('main')->setDot($isDot)->call('getList', [
            'serviceName' => $serviceName,
            'subscriber' => ZConfig::getField('soa', 'serviceName', ZConfig::get('project_name')),
        ]);
        if ($data) {
            $data = $data->getData();
            if (!empty($data['serviceList'])) {
                $serverList = $data['serviceList'];
                self::reload($serviceName, $serverList);
                return $serverList;
            }
        }
    }

    /**
     * @param $serviceName
     * @param $serverList
     * @param int $rebuild
     * @return bool
     */
    public static function reload($serviceName, $serverList, $rebuild = 1)
    {
        $path = ZPHP::getConfigPath() . DS . '..' . DS . 'public';
        if (!is_dir($path)) {
            if (!mkdir($path)) {
                return false;
            }
        }
        if ($rebuild) {
            foreach ($serverList as $index => $server) {
                $serverList[$server['ip'] . '_' . $server['port'] . '_' . $server['serverType']] = $server;
                unset($serverList[$index]);
            }
        }
        $filename = $path . DS . 'service_' . $serviceName . '.php';
        file_put_contents($filename, "<?php\rreturn array(
                        '$serviceName'=>" . var_export($serverList, true) . "
                    );");
        ZConfig::mergeFile($filename);
    }

    /**
     * @param $serviceName
     * @param $ip
     * @param $port
     * @param $type
     * @throws SchedulerException
     * @throws \Exception
     * @desc 服务选择成功，回调处理
     */
    public static function success($serviceName, $ip, $port, $type)
    {
        $soaConfig = ZConfig::get('soa');
        $serverList = self::getList($serviceName, $soaConfig);
        $key = "{$ip}_{$port}_{$type}";
        if (!empty($serverList[$key])) {
            $serverList[$key] = self::$selector->success($serverList[$key]);
            self::reload($serviceName, $serverList, 0);
        }
    }

    /**
     * @param $serviceName
     * @param $ip
     * @param $port
     * @param $type
     * @throws SchedulerException
     * @throws \Exception
     * @desc 服务选择失败，回调处理
     */
    public static function fail($serviceName, $ip, $port, $type)
    {
        $soaConfig = ZConfig::get('soa');
        $serverList = self::getList($serviceName, $soaConfig);
        $key = "{$ip}_{$port}_{$type}";
        if (!empty($serverList[$key])) {
            $serverList[$key] = self::$selector->fail($serverList[$key]);
            self::reload($serviceName, $serverList, 0);
        }

        return;

    }

}