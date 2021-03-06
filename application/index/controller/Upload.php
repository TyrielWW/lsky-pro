<?php
/**
 * User: Wisp X
 * Date: 2018/9/26
 * Time: 21:51
 * Link: https://github.com/wisp-x
 */

namespace app\index\controller;

use app\common\model\Images;
use app\common\model\Users;
use GuzzleHttp\Client;
use think\Db;
use think\Exception;
use think\exception\ErrorException;
use think\facade\Config;

class Upload extends Base
{
    public function upload()
    {
        if ($this->request->isPost()) {
            Db::startTrans();
            try {

                $data = $this->execute($this->user);

                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                return response($e->getMessage(), 500);
            } catch (ErrorException $e) {
                Db::rollback();
                return response($e->getMessage(), 500);
            }

            return $this->result($data, 200, '上传成功');
        }
    }

    /**
     * 执行上传，成功返回数据，否则直接抛出异常
     *
     * @param null|Users $user
     *
     * @return array
     * @throws Exception
     */
    public function execute($user = null)
    {
        if (!$this->config['allowed_tourist_upload'] && !$user) {
            throw new Exception('管理员关闭了游客上传！');
        }

        $image = $this->getImage();
        $size = $image->getSize();
        $mime = $image->getMime();
        $sha1 = $image->hash('sha1');
        $md5 = $image->hash('md5');

        if ($user) {
            if (($user->use_quota + $size) > $user->quota) {
                throw new Exception('保存失败！您的储存容量不足，请联系管理员！');
            }
        }

        // 当前储存策略
        $currentStrategy = strtolower($this->config['storage_strategy']);
        // 获取当前储存策略配置
        $strategyConfig = $this->currentStrategyConfig;
        // 获取当前驱动实例
        $strategy = $this->getStrategyInstance();

        $pathname = strtolower($this->makePathname($image->getInfo('name')));
        if (!$strategy->create($pathname, $image->getPathname())) {
            if (Config::get('app.app_debug')) {
                throw new Exception($strategy->getError());
            }
            throw new Exception('上传失败');
        }

        $cdnDomain = $currentStrategy . '_cdn_domain';
        $domain = $this->request->domain();
        if (array_key_exists($cdnDomain, $strategyConfig)) {
            if ($strategyConfig[$cdnDomain]) {
                $domain = $strategyConfig[$cdnDomain];
            }
        }
        $url = make_url($domain, $pathname);

        // 图片鉴黄
        if ($this->config['open_audit']) {
            $client = new Client();
            $response = $client->get("https://www.moderatecontent.com/api/v2?key={$this->config['audit_key']}&url={$url}");
            if (200 == $response->getStatusCode()) {
                $result = json_decode($response->getBody()->getContents());
                if (0 == $result->error_code) {
                    if ($result->rating_index >= $this->config['audit_index']) {
                        $strategy->delete($pathname);
                        throw new Exception('图片[' . $image->getInfo('name') . ']涉嫌违规，禁止上传！');
                    }
                } else {
                    $strategy->delete($pathname);
                    throw new Exception($result->error);
                }
            }
        }

        if (!Images::create([
            'user_id' => $user ? $user->id : 0,
            'strategy' => $currentStrategy,
            'path' => dirname($pathname),
            'name' => basename($pathname),
            'pathname' => $pathname,
            'size' => $size,
            'mime' => $mime,
            'sha1' => $sha1,
            'md5' => $md5
        ])) {
            $strategy->delete($pathname);
            throw new Exception('图片数据保存失败');
        }

        $data = [
            'name' => $image->getInfo('name'),
            'url' => $url,
        ];
        if ($this->user) {
            $data['quota'] = sprintf('%.2f', (float) $user->quota);
            $data['use_quota'] = sprintf('%.2f', (float) $user->use_quota);
        }

        return $data;
    }

    /**
     * 获取图片资源
     *
     * @return array|null|\think\File
     * @throws Exception
     */
    private function getImage()
    {
        $image = $this->request->file('image');
        if (null === $image) {
            throw new Exception('图片资源获取失败');
        }
        if (!is_uploaded_file($image->getPathname())) {
            throw new Exception('file is not uploaded via HTTP POST');
        }
        if (!$image->check([
            'size' => $this->config['upload_max_size'],
            'ext' => filter_comma($this->config['upload_allowed_exts']),
        ])) {
            throw new Exception($image->getError());
        }

        return $image;
    }

    /**
     * Make pathname
     *
     * @param $name 文件名
     *
     * @return string
     */
    private function makePathname($name)
    {
        $naming = Config::pull('naming');

        $path = trim(str_replace(
            array_column($naming['path'], 'name'),
            array_column($naming['path'], 'value'),
            $this->config['path_naming_rule']
        ), '/');

        $file = trim(str_replace(
            array_column($naming['file'], 'name'),
            array_column($naming['file'], 'value'),
            $this->config['file_naming_rule']
        ), '/') . '.' . get_file_ext($name);

        return $path . '/' . $file;
    }
}
