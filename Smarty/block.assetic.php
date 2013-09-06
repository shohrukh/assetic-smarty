<?php
/*
 * Smarty plugin
 * ------------------------------------------------------------
 * Type:       block
 * Name:       assetic
 * Purpose:    smarty plugin for Assetic
 * Author:     Pierre-Jean Parra
 * Version:    1.0
 *
 * ------------------------------------------------------------
 */
use Assetic\AssetManager;
use Assetic\FilterManager;
use Assetic\Filter;
use Assetic\Factory\AssetFactory;
use Assetic\Factory\LazyAssetManager;
use Assetic\Factory\Worker\CacheBustingWorker;
use Assetic\AssetWriter;
use Assetic\Asset\AssetCache;
use Assetic\Cache\FilesystemCache;

function smarty_block_assetic($params, $content, $template, &$repeat)
{
    // In debug mode, we have to be able to loop a certain number of times, so we use a static counter
    static $count;
    static $assetsUrls;

    $smarty = $template->smarty;
    if (!isset($smarty->assetic)) {
        $assets = 'assets.json';
        foreach((array)$smarty->config_dir as $_config_dir) {
            $_filepath = $_config_dir . DS . $assets;
            if (file_exists($_filepath)) {
                $smarty->assetic = $config = json_decode(file_get_contents($_filepath));
                $config->dist_path = $config->static_path . DS . $config->assets_path;
            }
        }
        if (!isset($smarty->assetic)) {
            throw new SmartyException("Unable to load config file \"{$assets}\"");
        }
    }
    $config = $smarty->assetic;

    // Opening tag (first call only)
    if ($repeat) {

        $am = new AssetManager();
        $fm = new FilterManager();
        $fm->set('less', new Filter\LessphpFilter());
        $fm->set('sass', new Filter\Sass\SassFilter());

        // Factory setup
        $factory = new AssetFactory($config->app_path);
        $factory->setAssetManager($am);
        $factory->setFilterManager($fm);
        $factory->setDefaultOutput('*.'.$params['output']);
        $factory->setDebug($params['debug']);
        $lam = new LazyAssetManager($factory);
        $factory->addWorker(new CacheBustingWorker($lam));

        if (isset($params['filters'])) {
            $filters = explode(',', $params['filters']);
        } else {
            $filters = array();
        }

        // Prepare the assets writer
        $writer = new AssetWriter($config->dist_path);

        // If a bundle name is provided
        if (isset($params['bundle'])) {
            $asset = $factory->createAsset(
                $config->bundles->$params['output']->$params['bundle'],
                $filters
            );

            $cache = new AssetCache(
                $asset,
                new FilesystemCache($config->dist_path)
            );

            $writer->writeAsset($cache);
        // If individual assets are provided
        } elseif (isset($params['assets'])) {
            $assets = array();
            // Include only the references first
            foreach (explode(',', $params['assets']) as $a) {
                // If the asset is found in the dependencies file, let's create it
                // If it is not found in the assets but is needed by another asset and found in the references,
                // don't worry, it will be automatically created
                if (isset($config->dependencies->$params['output']->assets->$a)) {
                    // Create the reference assets if they don't exist
                    foreach ($config->dependencies->$params['output']->assets->$a as $ref) {
                        try {
                            $am->get($ref);
                        }
                        catch (InvalidArgumentException $e) {
                            $path = $config->dependencies->$params['output']->references->$ref;

                            $assetTmp = $factory->createAsset($path);
                            $am->set($ref, $assetTmp);
                            $assets[] = '@'.$ref;
                        }
                    }
                }
            }

            // Now, include assets
            foreach (explode(',', $params['assets']) as $a) {
                // Add the asset to the list if not already present, as a reference or as a simple asset
                $ref = null;
                if (isset($config->dependencies->$params['output']))
                foreach ($config->dependencies->$params['output']->references as $name => $file) {
                    if ($file == $a) {
                        $ref = $name;
                        break;
                    }
                }

                if (array_search($a, $assets) === FALSE && ($ref === null || array_search('@' . $ref, $assets) === FALSE)) {
                    $assets[] = $a;
                }
            }

            // Create the asset
            $asset = $factory->createAsset(
                $assets,
                $filters
            );

            $cache = new AssetCache(
                $asset,
                new FilesystemCache($config->dist_path)
            );

            $writer->writeAsset($cache);
        }

        // If debug mode is active, we want to include assets separately
        if ($params['debug']) {
            $assetsUrls = array();
            foreach ($asset as $a) {

                $cache = new AssetCache(
                    $a,
                    new FilesystemCache($config->dist_path)
                );

                $writer->writeAsset($cache);
                $assetsUrls[] = $a->getTargetPath();
            }
            // It's easier to fetch the array backwards, so we reverse it to insert assets in the right order
            $assetsUrls = array_reverse($assetsUrls);

            $count = count($assetsUrls);

            $template->assign('asset_url', $config->assets_path.'/'.$assetsUrls[$count-1]);

        // Production mode, include an all-in-one asset
        } else {
            $template->assign('asset_url', $config->assets_path.'/'.$asset->getTargetPath());
        }

    // Closing tag
    } else {
        if (isset($content)) {
            // If debug mode is active, we want to include assets separately
            if ($params['debug']) {
                $count--;
                if ($count > 0) {
                    $template->assign('asset_url', $config->assets_path.'/'.$assetsUrls[$count-1]);
                }
                $repeat = $count > 0;
            }

            return $content;
        }
    }

}
