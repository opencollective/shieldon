<?php declare(strict_types=1);
/*
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shieldon\Driver;

use Redis;

/**
 * Redis Driver
 */
class RedisDriver extends DriverProvider
{

    /**
     * Redis instance.
     *
     * @var object
     */
    protected $redis;

    /**
     * Constructor.
     *
     * @param Redis $redis
     */
    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Set data channel.
     *
     * @param string $channel
     *
     * @return void
     */
    public function setChannel(string $channel): void
    {
        $this->channel = $channel;

        if (! empty($this->channel)) {
            $this->tableLogs = $this->channel . ':shieldon_logs';
            $this->tableRuleList = $this->channel . ':shieldon_rule_list';
            $this->tableSessions = $this->channel . ':shieldon_sessions';
        }
    }

    /**
     * Initialize data tables.
     *
     * @param bool $dbCheck This is for creating data tables automatically
     *                      Turn it off, if you don't want to check data tables every pageview.
     *
     * @return void
     */
    protected function doInitialize($dbCheck = true): void
    {
        if (! $this->isInitialized) {
            if (! empty($this->channel)) {
                $this->setChannel($this->channel);
            }
        }

        $this->isInitialized = true;
    }

    /**
     * {@inheritDoc}
     */
    protected function doFetchAll(string $type = 'log'): array
    {
        $results = [];

        switch ($type) {

            case 'rule':
            case 'log':
            case 'session':

                $keys = $this->redis->keys($this->getNamespace($type) . ':*');

                foreach($keys as $key) {
                    $content = $this->redis->get($key);
                    $content = json_decode($content, true);

                    if ($type === 'session') {
                        $sort = $content['microtimesamp'] . '.' . $content['id']; 
                    } else {
                        $sort = $content['log_ip'];
                    }

                    $results[$sort] = $content;   
                }

                // Sort by ascending timesamp (microtimesamp).
                ksort($results);
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    protected function doFetch(string $ip, string $type = 'log'): array
    {
        $results = [];

        if (! $this->checkExist($ip, $type)) {
            return $results;
        }

        switch ($type) {

            case 'rule':
            case 'session':
                $content = $this->redis->get($this->getKeyName($ip, $type));
                $resultData = json_decode($content, true);

                if (is_array($resultData)) {
                    $results = $resultData;
                }

            case 'log':
                $content = $this->redis->get($this->getKeyName($ip, $type));
                $resultData = json_decode($content, true);

                if (! empty($resultData['log_data'])) {
                    $results = $resultData['log_data']; 
                }
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    protected function checkExist(string $ip, string $type = 'log'): bool
    {
        $isExist = $this->redis->exists($this->getKeyName($ip, $type));

        // This function took a single argument and returned TRUE or FALSE in phpredis versions < 4.0.0.

        // @codeCoverageIgnoreStart
        if (is_bool($isExist)) {
            return $isExist;
        }

        return $isExist > 0;
        // @codeCoverageIgnoreEnd
    }

    /**
     * {@inheritDoc}
     */
    protected function doSave(string $ip, array $data, string $type = 'log', $expire = 0): bool
    {
        switch ($type) {

            case 'rule':
                $logData = $data;
                $logData['log_ip'] = $ip;
                break;

            case 'log':
                $logData['log_ip'] = $ip;
                $logData['log_data'] = $data;
                break;

            case 'session':
                $logData = $data;
                break;
        }

        if ($expire > 0) {
            return $this->redis->setex(
                $this->getKeyName($ip, $type),
                $expire,
                json_encode($logData)
            );
        }

        return $this->redis->set($this->getKeyName($ip, $type), json_encode($logData));
    }

    /**
     * {@inheritDoc}
     */
    protected function doDelete(string $ip, string $type = 'log'): bool
    {
        switch ($type) {
            case 'rule':
            case 'log':
            case 'session':
                return $this->redis->delete($this->getKeyName($ip, $type)) >= 0;
        }
        return false;
    }

    /**
     * {@inheritDoc}
     */
    protected function doRebuild(): bool
    {
        foreach (['rule', 'log', 'session'] as $type) {
            $keys = $this->redis->keys($this->getNamespace($type) . ':*');

            if (! empty($keys)) {
                foreach($keys as $key) {
                    $this->redis->delete($key);
                }
            }
        }
        return false;
    }

    /**
     * Get key name.
     *
     * @param string $ip
     * @param string $type
     *
     * @return string
     */
    private function getKeyName(string $ip, string $type = 'log'): string
    {
        switch ($type) {
            case 'log'    : return $this->tableLogs     . ':' . $ip;
            case 'session': return $this->tableSessions . ':' . $ip;
            case 'rule'   : return $this->tableRuleList . ':' . $ip;
        }
        return '';
    }

    /**
     * Get namespace.
     *
     * @param string $type
     *
     * @return string
     */
    private function getNamespace(string $type = 'log'): string
    {
        switch ($type) {
            case 'log'    : return $this->tableLogs;
            case 'session': return $this->tableSessions;
            case 'rule'   : return $this->tableRuleList;
        }
        return '';
    }
}