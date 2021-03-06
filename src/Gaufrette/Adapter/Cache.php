<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\File;
use Gaufrette\Adapter\InMemory as InMemoryAdapter;

/**
 * Cache adapter
 *
 * @package Gaufrette
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class Cache implements Adapter
{
	/**
	 * @var Adapter
	 */
    protected $source;

	/**
	 * @var Adapter
	 */
    protected $cache;

	/**
	 * @var integer
	 */
    protected $ttl;

	/**
	 * @var Adapter
	 */
    protected $serializeCache;

    /**
     * Constructor
     *
     * @param  Adapter $source  		The source adapter that must be cached
     * @param  Adapter $cache   		The adapter used to cache the source
     * @param  integer $ttl     		Time to live of a cached file
     * @param  Adapter $serializeCache  The adapter used to cache serializations
     */
    public function __construct(Adapter $source, Adapter $cache, $ttl = 0, Adapter $serializeCache = null)
    {
        $this->source = $source;
        $this->cache = $cache;
        $this->ttl = $ttl;

        if (!$serializeCache) {
            $serializeCache = new InMemoryAdapter();
        }
        $this->serializeCache = $serializeCache;
    }

    /**
     * Returns the time to live of the cache
     *
     * @return integer $ttl
     */
    public function getTtl() {
        return $this->ttl;
    }

    /**
     * Defines the time to live of the cache
     *
     * @param  integer $ttl
     */
    public function setTtl($ttl) {
        $this->ttl = $ttl;
    }

    /**
     * {@InheritDoc}
     */
    public function read($key)
    {
        if ($this->needsReload($key)) {
            $contents = $this->source->read($key);
            $this->cache->write($key, $contents);
        } else {
            $contents = $this->cache->read($key);
        }

        return $contents;
    }

    /**
     * {@InheritDoc}
     */
    public function rename($key, $new)
    {
        $this->source->rename($key, $new);
        $this->cache->rename($key, $new);
    }

    /**
     * {@InheritDoc}
     */
    public function write($key, $content, array $metadata = null)
    {
        $this->source->write($key, $content);
        $this->cache->write($key, $content);
    }

    /**
     * {@InheritDoc}
     */
    public function exists($key)
    {
        return $this->source->exists($key);
    }

    /**
     * {@InheritDoc}
     */
    public function mtime($key)
    {
        return $this->source->mtime($key);
    }

    /**
     * {@inheritDoc}
     */
    public function checksum($key)
    {
        return $this->source->checksum($key);
    }

    /**
     * {@inheritDoc}
     */
    public function keys()
    {
        $cacheFile = 'keys.cache';
        if ($this->needsRebuild($cacheFile)) {
            $keys = $this->source->keys();
            $this->serializeCache->write($cacheFile, serialize($keys));
        } else {
            $keys = unserialize($this->serializeCache->read($cacheFile));
        }

        return $keys;
    }

    /**
     * Creates a new File instance and returns it
     *
     * @param  string $key
     * @return File
     */
    public function get($key, $filesystem)
    {
        if (is_callable(array($this->source, 'get'))) {
            // If possible, delegate getting the file object to the source adapter.
            return $this->source->get($key, $filesystem);
        }

        return new File($key, $filesystem);
    }

    /**
     * @return array
     */
    public function listDirectory($directory = '')
    {
        $listing = null;

        if (method_exists($this->source, 'listDirectory')) {
            $cacheFile = 'dir-' . md5($directory) . '.cache';

            if ($this->needsRebuild($cacheFile)) {
                $listing = $this->source->listDirectory($directory);
                $this->serializeCache->write($cacheFile, serialize($listing));
            } else {
                $listing = unserialize($this->serializeCache->read($cacheFile));
            }
        }

        return $listing;
    }

    /**
     * {@InheritDoc}
     */
    public function delete($key)
    {
        $this->source->delete($key);
        $this->cache->delete($key);
    }

    /**
     * Indicates whether the cache for the specified key needs to be reloaded
     *
     * @param  string $key
     */
    public function needsReload($key)
    {
        $needsReload = true;

        if ($this->cache->exists($key)) {
            try {
                $dateCache = $this->cache->mtime($key);

                if (time() - $this->ttl < $dateCache) {
                    $dateSource = $this->source->mtime($key);
                    $needsReload = $dateCache < $dateSource;
                } else {
                    $needsReload = false;
                }
            } catch (\RuntimeException $e) { }
        }

        return $needsReload;
    }

    /**
     * Indicates whether the serialized cache file needs to be rebuild
     *
     * @param  string $cacheFile
     */
    public function needsRebuild($cacheFile)
    {
        $needsRebuild = true;

        if ($this->serializeCache->exists($cacheFile)) {
            try {
                $needsRebuild = time() - $this->ttl > $this->serializeCache->mtime($cacheFile);
            } catch (\RuntimeException $e) { }
        }

        return $needsRebuild;
    }

    /**
     * {@InheritDoc}
     */
    public function supportsMetadata()
    {
        return false;
    }
}
