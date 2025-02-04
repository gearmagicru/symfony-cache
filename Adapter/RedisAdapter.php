<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Adapter;

use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\Traits\RedisTrait;

class RedisAdapter extends AbstractAdapter
{
    use RedisTrait;

    public function __construct(\Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|\Relay\Relay $redis, string $namespace = '', int $defaultLifetime = 0, ?MarshallerInterface $marshaller = null)
    {
        $this->init($redis, $namespace, $defaultLifetime, $marshaller);
    }

    /**
     * Factory.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string[] $params Adapter configuration options.
     * 
     * @return RedisAdapter
     */
    public static function factory($params)
    {
        $client = self::createConnection($params['dsn'], $params['options'] ?? []);
        return new self(
            $client,
            $params['namespace'] ?? '',
            $params['defaultLifetime'] ?? 0,
            $params['marshaller'] ?? null
        );
    }

    /**
     * {@inheritdoc}
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function keys(string $pattern = '*', int $limit = 0): array
    {
        return $this->redis->keys($pattern);
    }

   /**
     * Return client.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @return mixed
     */
    public function getClient()
    {
        return $this->redis;
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hmset
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashMultiSet(string $hashKey, array $values): bool
    {
        return $this->redis->hMSet($hashKey, $values);
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hmget
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashMultiGet(string $hashKey, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        $value = $this->redis->hMGet($hashKey, $keys);
        return is_array($value) ? $value : [];
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hgetall
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashGetAll(string $hashKey): ?array
    {
        $value = $this->redis->hGetAll($hashKey);
        return is_array($value) ? $value : null;
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hget
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashGet(string $hashKey, $key)
    {
        $value = $this->redis->hGet($hashKey, $key);
        return $value === false ? null : $value;
    }

    /**
     * {@inheritdoc}
     * 
     * @see RedisAdapter::hashGetAll()
     * @see RedisAdapter::hashMultiSet
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashSetOrGetAll(string $hashKey, callable $callback = null): array
    {
        if ($this->hasItem($hashKey)) {
            $values = $this->hashGetAll($hashKey);
        } else {
            $values = $callback();
            $this->hashMultiSet($hashKey, $values);
        }
        return $values;
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hdel
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashDelete(string $hashKey, array $keys): int
    {
        return (int) call_user_func_array([$this->redis, 'hDel'], array_merge([$hashKey], $keys));
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hset
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashSet(string $hashKey, string $key, $value)
    {
        return $this->redis->hSet($hashKey, $key, $value);
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hlen
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashLength(string $hashKey): int
    {
        return $this->redis->hLen($hashKey);
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hkeys
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashKeys(string $hashKey): array
    {
        return $this->redis->hKeys($hashKey);
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hvals
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashValues(string $hashKey): array
    {
        return $this->redis->hVals($hashKey);
    }

    /**
     * {@inheritdoc}
     * 
     * @see https://github.com/phpredis/phpredis#hexists
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     */
    public function hashExists(string $hashKey, $key): bool
    {
        return $this->redis->hExists($hashKey, $key);
    }
}
