<?php

namespace GuillermoMartinez\Filemanager\Service;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Options;
use Psr\Log\LoggerInterface;

/**
 * ConfigResolver Service
 * 
 * Provides flexible, validated configuration management using Symfony's OptionsResolver.
 * Ensures all configuration options are properly validated, normalized, and have sensible defaults.
 * 
 * Phase 3: Config Resolution
 * 
 * @author Guillermo Martinez
 * @package GuillermoMartinez\Filemanager\Service
 */
class ConfigResolver
{
    /**
     * Logger instance
     * 
     * @var LoggerInterface|null
     */
    private $log;

    /**
     * Symfony OptionsResolver for configuration validation
     * 
     * @var OptionsResolver
     */
    private $resolver;

    /**
     * Constructor
     * 
     * @param LoggerInterface|null $log Optional logger instance
     */
    public function __construct($log = null)
    {
        $this->log = $log;
        $this->resolver = new OptionsResolver();
        $this->configureOptions();
    }

    /**
     * Configure all available options with defaults, types, and validation
     * 
     * Defines the complete configuration schema for Filemanager package.
     * All options are typed, documented, and have sensible defaults.
     * 
     * @return void
     */
    private function configureOptions()
    {
        // ==== ROOT LEVEL OPTIONS ====
        
        $this->resolver->setDefaults(array(
            // Path Configuration
            'doc_root' => '',
            'url' => '/',
            'source' => 'userfiles',
            'folder_thumb' => '_thumbs',
            'folder_excludes' => array(),
            
            // Debug Configuration
            'debug' => false,
            'debugfile' => 'log/filemanager.log',
            
            // Authentication (framework typically owns this)
            'require_auth' => false,
            
            // Storage Configuration
            'storage' => null,
            
            // File Extension Whitelist
            'ext' => array(
                'jpg', 'jpeg', 'gif', 'png', 'svg',
                'txt', 'pdf',
                'odp', 'ods', 'odt', 'rtf',
                'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'csv',
                'ogv', 'mp4', 'webm', 'm4v',
                'ogg', 'mp3', 'wav',
                'zip', 'rar'
            ),
            
            // Type File Filter (null = no filter, or 'image', 'video', 'audio', 'document')
            'type_file' => null,
            
            // Upload Configuration
            'upload' => array(
                'number' => 5,
                'overwrite' => false,
                'size_max' => 10  // MB
            ),
            
            // Image Processing Configuration
            'images' => array(
                'images_ext' => array('jpg', 'jpeg', 'gif', 'png'),
                'resize' => array(
                    'small' => array(120, 90, true, true),
                ),
                'quality' => 100,
                'service' => null,  // Injected ImageService instance
            ),
        ));

        // ==== ALLOWED TYPES ====
        
        // Root level option types
        $this->resolver->setAllowedTypes('doc_root', 'string');
        $this->resolver->setAllowedTypes('url', 'string');
        $this->resolver->setAllowedTypes('source', 'string');
        $this->resolver->setAllowedTypes('folder_thumb', 'string');
        $this->resolver->setAllowedTypes('folder_excludes', 'array');
        $this->resolver->setAllowedTypes('debug', 'bool');
        $this->resolver->setAllowedTypes('debugfile', 'string');
        $this->resolver->setAllowedTypes('require_auth', 'bool');
        $this->resolver->setAllowedTypes('storage', array('null', 'array'));
        $this->resolver->setAllowedTypes('ext', 'array');
        $this->resolver->setAllowedTypes('type_file', array('null', 'string'));
        $this->resolver->setAllowedTypes('upload', 'array');
        $this->resolver->setAllowedTypes('images', 'array');

        // ==== ALLOWED VALUES ====
        
        // Debug level options
        $this->resolver->setAllowedValues('type_file', function($value) {
            if ($value === null) return true;
            return in_array($value, ['image', 'video', 'audio', 'document']);
        });

        // ==== NESTED VALIDATORS (Closures) ====
        
        // Validate and normalize 'ext' (extension list)
        $this->resolver->setNormalizer('ext', function(Options $options, $value) {
            if (!is_array($value)) {
                if ($this->log) $this->log->warning('ConfigResolver::ext normalizer - converting non-array to empty array');
                return array();
            }
            
            // Ensure all extensions are lowercase and strings
            $normalized = array_map('strtolower', array_filter($value, 'is_string'));
            
            if ($this->log) {
                $this->log->debug('ConfigResolver::ext normalizer - normalized ' . count($value) . ' extensions to ' . count($normalized));
            }
            
            return $normalized;
        });

        // Validate 'upload' sub-configuration
        $this->resolver->setNormalizer('upload', function(Options $options, $value) {
            if (!is_array($value)) {
                if ($this->log) $this->log->warning('ConfigResolver::upload normalizer - converting non-array to defaults');
                return array('number' => 5, 'overwrite' => false, 'size_max' => 10);
            }

            // Set defaults for missing keys
            $defaults = array('number' => 5, 'overwrite' => false, 'size_max' => 10);
            $config = array_merge($defaults, $value);

            // Validate types within upload config
            if (!is_int($config['number']) || $config['number'] < 1) {
                if ($this->log) $this->log->warning('ConfigResolver::upload normalizer - invalid number, using default');
                $config['number'] = 5;
            }

            if (!is_bool($config['overwrite'])) {
                if ($this->log) $this->log->warning('ConfigResolver::upload normalizer - overwrite not bool, converting');
                $config['overwrite'] = (bool) $config['overwrite'];
            }

            if (!is_numeric($config['size_max']) || $config['size_max'] < 1) {
                if ($this->log) $this->log->warning('ConfigResolver::upload normalizer - invalid size_max, using default');
                $config['size_max'] = 10;
            }

            if ($this->log) {
                $this->log->debug('ConfigResolver::upload normalizer - config validated: number=' . $config['number'] . ', size_max=' . $config['size_max']);
            }

            return $config;
        });

        // Validate 'images' sub-configuration
        $this->resolver->setNormalizer('images', function(Options $options, $value) {
            if (!is_array($value)) {
                if ($this->log) $this->log->warning('ConfigResolver::images normalizer - converting non-array to defaults');
                return array(
                    'images_ext' => array('jpg', 'jpeg', 'gif', 'png'),
                    'resize' => array('small' => array(120, 90, true, true)),
                    'quality' => 100,
                    'service' => null,
                );
            }

            // Set defaults for missing keys
            $defaults = array(
                'images_ext' => array('jpg', 'jpeg', 'gif', 'png'),
                'resize' => array('small' => array(120, 90, true, true)),
                'quality' => 100,
                'service' => null,
            );
            $config = array_merge($defaults, $value);

            // Normalize images_ext to lowercase
            if (is_array($config['images_ext'])) {
                $config['images_ext'] = array_map('strtolower', array_filter($config['images_ext'], 'is_string'));
            } else {
                $config['images_ext'] = $defaults['images_ext'];
                if ($this->log) $this->log->warning('ConfigResolver::images normalizer - images_ext not array');
            }

            // Validate quality is between 1-100
            if (!is_int($config['quality']) || $config['quality'] < 1 || $config['quality'] > 100) {
                if ($this->log) $this->log->warning('ConfigResolver::images normalizer - invalid quality, using default');
                $config['quality'] = 100;
            }

            // Validate resize is array
            if (!is_array($config['resize'])) {
                if ($this->log) $this->log->warning('ConfigResolver::images normalizer - resize not array, using defaults');
                $config['resize'] = $defaults['resize'];
            }

            if ($this->log) {
                $this->log->debug('ConfigResolver::images normalizer - config validated: ext count=' . count($config['images_ext']) . ', quality=' . $config['quality']);
            }

            return $config;
        });

        // Validate 'storage' sub-configuration (if provided)
        $this->resolver->setNormalizer('storage', function(Options $options, $value) {
            if ($value === null) {
                if ($this->log) $this->log->debug('ConfigResolver::storage normalizer - storage is null (optional)');
                return null;
            }

            if (!is_array($value)) {
                if ($this->log) $this->log->warning('ConfigResolver::storage normalizer - converting non-array to null');
                return null;
            }

            // Storage config must have 'driver' key
            if (!isset($value['driver'])) {
                if ($this->log) $this->log->warning('ConfigResolver::storage normalizer - missing driver key');
                return null;
            }

            if ($this->log) {
                $this->log->debug('ConfigResolver::storage normalizer - storage config validated: driver=' . $value['driver']);
            }

            return $value;
        });
    }

    /**
     * Resolve and validate configuration
     * 
     * Takes raw configuration input, validates all options, normalizes values,
     * and returns a complete, validated configuration array ready for use.
     * 
     * @param array $config User-provided configuration options
     * @return array Resolved and validated configuration
     * @throws \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     * @throws \Symfony\Component\OptionsResolver\Exception\MissingOptionsException
     */
    public function resolve(array $config)
    {
        if ($this->log) {
            $this->log->info('ConfigResolver::resolve - resolving configuration with ' . count($config) . ' user options');
        }

        try {
            $resolved = $this->resolver->resolve($config);
            
            if ($this->log) {
                $this->log->info('ConfigResolver::resolve - configuration resolved successfully');
                $this->log->debug('ConfigResolver::resolve - resolved config keys: ' . implode(', ', array_keys($resolved)));
            }

            return $resolved;
        } catch (\Exception $e) {
            if ($this->log) {
                $this->log->error('ConfigResolver::resolve - resolution failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Get the OptionsResolver instance for advanced customization
     * 
     * Allows extending the configuration schema if needed.
     * 
     * @return OptionsResolver
     */
    public function getResolver()
    {
        return $this->resolver;
    }
}
