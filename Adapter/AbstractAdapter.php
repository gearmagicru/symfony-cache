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

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Component\Cache\Traits\AbstractAdapterTrait;
use Symfony\Component\Cache\Traits\ContractsTrait;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
abstract class AbstractAdapter implements AdapterInterface, CacheInterface, LoggerAwareInterface, ResettableInterface
{
    use AbstractAdapterTrait;
    use ContractsTrait;

    /**
     * @internal
     */
    protected const NS_SEPARATOR = ':';

    private static bool $apcuSupported;

    protected function __construct(string $namespace = '', int $defaultLifetime = 0)
    {
        $this->namespace = '' === $namespace ? '' : CacheItem::validateKey($namespace).static::NS_SEPARATOR;
        $this->defaultLifetime = $defaultLifetime;
        if (null !== $this->maxIdLength && \strlen($namespace) > $this->maxIdLength - 24) {
            throw new InvalidArgumentException(\sprintf('Namespace must be %d chars max, %d given ("%s").', $this->maxIdLength - 24, \strlen($namespace), $namespace));
        }
        self::$createCacheItem ??= \Closure::bind(
            static function ($key, $value, $isHit) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $value;
                $item->isHit = $isHit;
                $item->unpack();

                return $item;
            },
            null,
            CacheItem::class
        );
        self::$mergeByLifetime ??= \Closure::bind(
            static function ($deferred, $namespace, &$expiredIds, $getId, $defaultLifetime) {
                $byLifetime = [];
                $now = microtime(true);
                $expiredIds = [];

                foreach ($deferred as $key => $item) {
                    $key = (string) $key;
                    if (null === $item->expiry) {
                        $ttl = 0 < $defaultLifetime ? $defaultLifetime : 0;
                    } elseif (!$item->expiry) {
                        $ttl = 0;
                    } elseif (0 >= $ttl = (int) (0.1 + $item->expiry - $now)) {
                        $expiredIds[] = $getId($key);
                        continue;
                    }
                    $byLifetime[$ttl][$getId($key)] = $item->pack();
                }

                return $byLifetime;
            },
            null,
            CacheItem::class
        );
    }

    /**
     * Returns the best possible adapter that your runtime supports.
     *
     * Using ApcuAdapter makes system caches compatible with read-only filesystems.
     */
    public static function createSystemCache(string $namespace, int $defaultLifetime, string $version, string $directory, ?LoggerInterface $logger = null): AdapterInterface
    {
        $opcache = new PhpFilesAdapter($namespace, $defaultLifetime, $directory, true);
        if (null !== $logger) {
            $opcache->setLogger($logger);
        }

        if (!self::$apcuSupported ??= ApcuAdapter::isSupported()) {
            return $opcache;
        }

        if ('cli' === \PHP_SAPI && !filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL)) {
            return $opcache;
        }

        $apcu = new ApcuAdapter($namespace, intdiv($defaultLifetime, 5), $version);
        if (null !== $logger) {
            $apcu->setLogger($logger);
        }

        return new ChainAdapter([$apcu, $opcache]);
    }

    public static function createConnection(#[\SensitiveParameter] string $dsn, array $options = []): mixed
    {
        if (str_starts_with($dsn, 'redis:') || str_starts_with($dsn, 'rediss:')) {
            return RedisAdapter::createConnection($dsn, $options);
        }
        if (str_starts_with($dsn, 'memcached:')) {
            return MemcachedAdapter::createConnection($dsn, $options);
        }
        if (str_starts_with($dsn, 'couchbase:')) {
            if (class_exists('CouchbaseBucket') && CouchbaseBucketAdapter::isSupported()) {
                return CouchbaseBucketAdapter::createConnection($dsn, $options);
            }

            return CouchbaseCollectionAdapter::createConnection($dsn, $options);
        }
        if (preg_match('/^(mysql|oci|pgsql|sqlsrv|sqlite):/', $dsn)) {
            return PdoAdapter::createConnection($dsn, $options);
        }

        throw new InvalidArgumentException('Unsupported DSN: it does not start with "redis[s]:", "memcached:", "couchbase:", "mysql:", "oci:", "pgsql:", "sqlsrv:" nor "sqlite:".');
    }

    public function commit(): bool
    {
        $ok = true;
        $byLifetime = (self::$mergeByLifetime)($this->deferred, $this->namespace, $expiredIds, $this->getId(...), $this->defaultLifetime);
        $retry = $this->deferred = [];

        if ($expiredIds) {
            try {
                $this->doDelete($expiredIds);
            } catch (\Exception $e) {
                $ok = false;
                CacheItem::log($this->logger, 'Failed to delete expired items: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);
            }
        }
        foreach ($byLifetime as $lifetime => $values) {
            try {
                $e = $this->doSave($values, $lifetime);
            } catch (\Exception $e) {
            }
            if (true === $e || [] === $e) {
                continue;
            }
            if (\is_array($e) || 1 === \count($values)) {
                foreach (\is_array($e) ? $e : array_keys($values) as $id) {
                    $ok = false;
                    $v = $values[$id];
                    $type = get_debug_type($v);
                    $message = \sprintf('Failed to save key "{key}" of type %s%s', $type, $e instanceof \Exception ? ': '.$e->getMessage() : '.');
                    CacheItem::log($this->logger, $message, ['key' => substr($id, \strlen($this->namespace)), 'exception' => $e instanceof \Exception ? $e : null, 'cache-adapter' => get_debug_type($this)]);
                }
            } else {
                foreach ($values as $id => $v) {
                    $retry[$lifetime][] = $id;
                }
            }
        }

        // When bulk-save failed, retry each item individually
        foreach ($retry as $lifetime => $ids) {
            foreach ($ids as $id) {
                try {
                    $v = $byLifetime[$lifetime][$id];
                    $e = $this->doSave([$id => $v], $lifetime);
                } catch (\Exception $e) {
                }
                if (true === $e || [] === $e) {
                    continue;
                }
                $ok = false;
                $type = get_debug_type($v);
                $message = \sprintf('Failed to save key "{key}" of type %s%s', $type, $e instanceof \Exception ? ': '.$e->getMessage() : '.');
                CacheItem::log($this->logger, $message, ['key' => substr($id, \strlen($this->namespace)), 'exception' => $e instanceof \Exception ? $e : null, 'cache-adapter' => get_debug_type($this)]);
            }
        }

        return $ok;
    }

    /**
     * Factory.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string[] $params Adapter configuration options.
     * 
     * @return AbstractAdapter
     */
    public static function factory($params)
    {
        return new self(
            $params['namespace'] ?? '',
            $params['defaultLifetime'] ?? 0
        );
    }

    /**
     * Возврашает или устанавливает значение.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $кey Ключ.
     * @param callable $callback
     * @param int $expiry
     * 
     * @return array
     */
    public function getOrSet(string $key, callable $callback, int $expiry = null)
    {
        if ($this->hasItem($key)) {
            return $this->getItem($key)->get();
        } else {
            $item = $this->getItem($key);
            $value = $callback();
            $item->set($value);
            if ($expiry) {
                $item->expiresAfter($expiry);
            }
            $this->save($item);
            return $value;
        }
    }

    /**
     * Возвращает массив ключей найденных по указанному шаблону.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $pattern Шаблон поиска ключей (по умолчанию '*').
     * @param int $limit Допустимое количество возвращаемых ключей.
     * 
     * @return array
     */
    public function keys(string $pattern = '*', int $limit = 0): array
    {
        return [];
    }

    /**
     * Заполняет весь хеш по указанному ключу. 
     * 
     * Нестроковые значения преобразуются в строку с использованием стандартного 
     * (строкового) приведения. Значения `null` сохраняются как пустые строки.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $key Ключ хэша.
     * @param array $values Массив в виде пар 'ключ - значение'.
     * 
     * @return bool Если `true`, удалось успешно заполнить хэш.
     */
    public function hashMultiSet(string $hashKey, array $values): bool
    {
        return false;
    }

    /**
     * Возвращает значения, связанные с указанными полями (ключами) в хеше.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * @param array $keys Поля (ключи) с которыми связаны элементы масссива.
     * 
     * @return array Массив элементов, значений указанных полей (ключей) в хеше с 
     *     ключами хеша в качестве ключей массива. Если не существуют ключи связанные с 
     *     элементы массива, то вернёт массив вида: `['key1' => false, 'key2' => false...]`.
     * 
     */
    public function hashMultiGet(string $hashKey, array $keys): array
    {
        return [];
    }

    /**
     * Возвращает массив всех элементов хэша.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * 
     * @return null|array Если `null`, содержимое хеша по указанному ключу не существует.
     */
    public function hashGetAll(string $hashKey): ?array
    {
        return null;
    }

    /**
     * Возвращает значение из хэша, хранящегося в ключе. 
     * 
     * Если хэш-таблица не существует или ключ не существует, возвращает null.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * @param string|int $hashKey Поле (ключ) хэш-таблицы.
     * 
     * @return null|mixed
     */
    public function hashGet(string $hashKey, $key)
    {
        return null;
    }

    /**
     * Возврашает или заполняет хэш-таблицу значениями.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * @param callable $callback
     * 
     * @return array
     */
    public function hashSetOrGetAll(string $hashKey, callable $callback = null): array
    {
        return [];
    }

    /**
     * Удаляет значение из хэша, хранящегося по указанному ключу. 
     * 
     * Если хэш-таблица не существует или ключ не существует, возвращается `false`.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * @param array $keys Поля (ключи) хэш-таблицы.
     * 
     * @return int Количество удалённых полей (ключей)  хэш-таблицы. Если хэш-таблица 
     *    не существует '0'.
     */
    public function hashDelete(string $hashKey, array $keys): int
    {
        return 0;
    }

    /**
     * Добавляет значение c полем (ключем) в хэш-таблицу.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * @param array $key Поле (ключ) хэш-таблицы.
     * @param mixed $value Значение поля (ключа) хэш-таблицы.
     * 
     * @return bool|int Если '1',значение не существовало и было успешно добавлено. 
     *     Если '0', значение уже существовало и было заменено. `false`, если произошла ошибка.
     */
    public function hashSet(string $hashKey, string $key, $value)
    {
        return [];
    }

    /**
     * Возвращает длину хэша в количестве элементов.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * 
     * @return mixed Длину хэша в количестве элементов. Есди хэш не существует, возвратит '0'.
     */
    public function hashLength(string $hashKey): int
    {
        return 0;
    }

    /**
     * Возвращает ключи в виде хэша в виде массива строк.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * 
     * @return array Массив элементов, ключи хеша. Это работает как `array_keys()` в PHP.
     */
    public function hashKeys(string $hashKey): array
    {
        return [];
    }

    /**
     * Возвращает значения  полей (ключей) хэш-таблицы в виде массива строк.
     * 
     * @author Anton Tivonenko <anton.tivonenko@gmail.com>
     * 
     * @param string $hashKey Ключ хэша.
     * 
     * @return array Массив элементов, значения хеша. Работает как `array_values()` в PHP.
     */
    public function hashValues(string $hashKey): array
    {
        return [];
    }

    /**
     * Проверяет, существует ли указанный элемент (поле) по указанному ключу хэша.
     * 
     * @param string $hashKey Ключ хэша.
     * @param array $key Поле (ключ) хэш-таблицы.
     * 
     * @return bool
     */
    public function hashExists(string $hashKey, $key): bool
    {
        return false;
    }
}
