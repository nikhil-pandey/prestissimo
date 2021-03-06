<?php
/*
 * hirak/prestissimo
 * @author Hiraku NAKANO
 * @license MIT https://github.com/hirak/prestissimo
 */
namespace Hirak\Prestissimo;

use Composer\Package;
use Composer\IO;
use Composer\Config;

/**
 *
 */
class ParallelDownloader
{
    /** @var IO/IOInterface */
    protected $io;

    /** @var Config */
    protected $config;

    /** @var int */
    protected $totalCnt = 0;
    protected $successCnt = 0;
    protected $failureCnt = 0;

    public function __construct(IO\IOInterface $io, Config $config)
    {
        $this->io = $io;
        $this->config = $config;
    }

    /**
     * @param Package\PackageInterface[] $packages
     * @param array $pluginConfig
     * @return void
     */
    public function download(array $packages, array $pluginConfig)
    {
        $mh = curl_multi_init();
        $unused = array();
        $maxConns = $pluginConfig['maxConnections'];
        for ($i = 0; $i < $maxConns; ++$i) {
            $unused[] = curl_init();
        }

        /// @codeCoverageIgnoreStart
        if (function_exists('curl_share_init')) {
            $sh = curl_share_init();
            curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);

            foreach ($unused as $ch) {
                curl_setopt($ch, CURLOPT_SHARE, $sh);
            }
        }

        if (function_exists('curl_multi_setopt')) {
            if ($pluginConfig['pipeline']) {
                curl_multi_setopt($mh, CURLMOPT_PIPELINING, true);
            }
        }
        /// @codeCoverageIgnoreEnd

        $cachedir = rtrim($this->config->get('cache-files-dir'), '\/');

        $chFpMap = array();
        $running = 0; //ref type
        $remains = 0; //ref type

        $this->totalCnt = count($packages);
        $this->successCnt = 0;
        $this->failureCnt = 0;
        $this->io->write("    Prefetch start: <comment>success: $this->successCnt, failure: $this->failureCnt, total: $this->totalCnt</comment>");
        do {
            // prepare curl resources
            while (count($unused) > 0 && count($packages) > 0) {
                $package = array_pop($packages);
                $filepath = $cachedir . DIRECTORY_SEPARATOR . static::getCacheKey($package);
                if (file_exists($filepath)) {
                    ++$this->successCnt;
                    continue;
                }
                $ch = array_pop($unused);

                // make file resource
                $chFpMap[(int)$ch] = $outputFile = new OutputFile($filepath);

                // make url
                $url = $package->getDistUrl();
                $host = parse_url($url, PHP_URL_HOST) ?: '';
                $request = new Aspects\HttpGetRequest($host, $url, $this->io);
                $request->verbose = $pluginConfig['verbose'];
                if (in_array($package->getName(), $pluginConfig['privatePackages'])) {
                    $request->maybePublic = false;
                } else {
                    $request->maybePublic = (bool)preg_match('%^(?:https|git)://github\.com%', $package->getSourceUrl());
                }
                $onPreDownload = Factory::getPreEvent($request);
                $onPreDownload->notify();

                $opts = $request->getCurlOpts();
                if ($pluginConfig['insecure']) {
                    $opts[CURLOPT_SSL_VERIFYPEER] = false;
                }
                if (! empty($pluginConfig['capath'])) {
                    $opts[CURLOPT_CAPATH] = $pluginConfig['capath'];
                }
                unset($opts[CURLOPT_ENCODING]);
                unset($opts[CURLOPT_USERPWD]); // ParallelDownloader doesn't support private packages.
                curl_setopt_array($ch, $opts);
                curl_setopt($ch, CURLOPT_FILE, $outputFile->getPointer());
                curl_multi_add_handle($mh, $ch);
            }

            // wait for any event
            do {
                // start multi download
                do {
                    $stat = curl_multi_exec($mh, $running);
                } while ($stat === CURLM_CALL_MULTI_PERFORM);

                switch (curl_multi_select($mh, 5)) {
                case -1:
                    usleep(250);
                    // fall through
                case 0:
                    continue 2;
                default:
                    do {
                        $stat = curl_multi_exec($mh, $running);
                    } while ($stat === CURLM_CALL_MULTI_PERFORM);

                    do {
                        if ($raised = curl_multi_info_read($mh, $remains)) {
                            $ch = $raised['handle'];
                            $errno = curl_errno($ch);
                            $info = curl_getinfo($ch);
                            curl_setopt($ch, CURLOPT_FILE, STDOUT);
                            $index = (int)$ch;
                            $outputFile = $chFpMap[$index];
                            unset($chFpMap[$index]);
                            if (CURLE_OK === $errno && 200 === $info['http_code']) {
                                ++$this->successCnt;
                            } else {
                                ++$this->failureCnt;
                                $outputFile->setFailure();
                            }
                            unset($outputFile);
                            $this->io->write($this->makeDownloadingText($info['url']));
                            curl_multi_remove_handle($mh, $ch);
                            $unused[] = $ch;
                        }
                    } while ($remains > 0);

                    if (count($packages) > 0) {
                        break 2;
                    }
                }
            } while ($running);
        } while (count($packages) > 0);
        $this->io->write("    Finished: <comment>success: $this->successCnt, failure: $this->failureCnt, total: $this->totalCnt</comment>");

        foreach ($unused as $ch) {
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    /**
     * @param string $url
     * @return string
     */
    private function makeDownloadingText($url)
    {
        $request = new Aspects\HttpGetRequest('example.com', $url, $this->io);
        $request->query = array();
        return "    <comment>$this->successCnt/$this->totalCnt</comment>:    {$request->getURL()}";
    }

    public static function getCacheKey(Package\PackageInterface $p)
    {
        $distRef = $p->getDistReference();
        if (preg_match('{^[a-f0-9]{40}$}', $distRef)) {
            return "{$p->getName()}/$distRef.{$p->getDistType()}";
        }

        return "{$p->getName()}/{$p->getVersion()}-$distRef.{$p->getDistType()}";
    }
}
