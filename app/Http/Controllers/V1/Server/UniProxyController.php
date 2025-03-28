<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;
use Illuminate\Http\JsonResponse;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly UserOnlineService $userOnlineService
    ) {
    }

    /**
     * 生成缓存键
     */
    private function cacheKey(string $type, string $suffix, int $nodeId): string
    {
        return CacheKey::get('SERVER_' . strtoupper($type) . '_' . $suffix, $nodeId);
    }

    /**
     * 生成带 ETag 的 JSON 响应
     */
    private function jsonResponseWithETag(Request $request, array $response): JsonResponse
    {
        $eTag = sha1(serialize($response));
        if ($request->header('If-None-Match', '') === "\"{$eTag}\"") {
            return response()->json(null, 304);
        }
        return response()->json($response)->header('ETag', "\"{$eTag}\"");
    }

    /**
     * 获取可用用户列表
     */
    public function user(Request $request): JsonResponse
    {
        ini_set('memory_limit', '-1');

        $node = $request->input('node_info');
        if (!$node || !isset($node->type, $node->id, $node->group_ids)) {
            return response()->json(['error' => 'Invalid node information'], 400);
        }

        Cache::put($this->cacheKey($node->type, 'LAST_CHECK_AT', $node->id), time(), 3600);
        $users = ServerService::getAvailableUsers($node->group_ids);

        return $this->jsonResponseWithETag($request, ['users' => $users]);
    }

    /**
     * 推送流量数据
     */
    public function push(Request $request): JsonResponse
    {
        $res = json_decode($request->getContent(), true);
        if (!is_array($res)) {
            return response()->json(['error' => 'Invalid data format'], 422);
        }

        // 过滤无效数据，只保留 [用户ID, 流量] 格式
        $data = array_filter($res, fn($item) => is_array($item) && count($item) === 2 && is_numeric($item[0]) && is_numeric($item[1]));

        if (empty($data)) {
            return response()->json(['success' => true]);
        }

        $node = $request->input('node_info');
        if (!$node || !isset($node->type, $node->id)) {
            return response()->json(['error' => 'Invalid node information'], 400);
        }

        // 更新在线用户数和推送时间
        Cache::put($this->cacheKey($node->type, 'ONLINE_USER', $node->id), count($data), 3600);
        Cache::put($this->cacheKey($node->type, 'LAST_PUSH_AT', $node->id), time(), 3600);

        // 处理流量数据
        (new UserService())->trafficFetch($node->toArray(), $node->type, $data);

        return response()->json(['success' => true]);
    }

    /**
     * 获取节点配置信息
     */
    public function config(Request $request): JsonResponse
    {
        $node = $request->input('node_info');
        if (!$node || !isset($node->type, $node->protocol_settings, $node->server_port, $node->host)) {
            return response()->json(['error' => 'Invalid node information'], 400);
        }

        // 基础配置信息
        $baseConfig = [
            'server_port' => (int) $node->server_port,
            'network' => data_get($node->protocol_settings, 'network'),
            'networkSettings' => data_get($node->protocol_settings, 'network_settings', null),
        ];

        // 根据协议类型获取不同的配置信息
        $response = match ($node->type) {
            'shadowsocks' => $this->getShadowsocksConfig($node, $baseConfig),
            'vmess' => [...$baseConfig, 'tls' => (int) $node->protocol_settings['tls']],
            'trojan' => [...$baseConfig, 'host' => $node->host, 'server_name' => $node->protocol_settings['server_name']],
            'vless' => [...$baseConfig, 'tls' => (int) $node->protocol_settings['tls'], 'flow' => $node->protocol_settings['flow']],
            default => []
        };

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60),
        ];

        if (!empty($node->route_ids)) {
            $response['routes'] = ServerService::getRoutes($node->route_ids);
        }

        return $this->jsonResponseWithETag($request, $response);
    }

    /**
     * 获取 Shadowsocks 配置
     */
    private function getShadowsocksConfig(object $node, array $baseConfig): array
    {
        return [
            ...$baseConfig,
            'cipher' => $node->protocol_settings['cipher'],
            'obfs' => $node->protocol_settings['obfs'],
            'obfs_settings' => $node->protocol_settings['obfs_settings'],
            'server_key' => match ($node->protocol_settings['cipher']) {
                '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                default => null,
            }
        ];
    }

    /**
     * 获取在线用户列表
     */
    public function alivelist(Request $request): JsonResponse
    {
        $node = $request->input('node_info');
        if (!$node || !isset($node->group_ids)) {
            return response()->json(['error' => 'Invalid node information'], 400);
        }

        // 获取设备限制的用户，并查询在线用户列表
        $deviceLimitUsers = ServerService::getAvailableUsers($node->group_ids)->where('device_limit', '>', 0);
        $alive = $this->userOnlineService->getAliveList($deviceLimitUsers);

        return response()->json(['alive' => (object) $alive]);
    }

    /**
     * 更新在线用户状态
     */
    public function alive(Request $request): JsonResponse
    {
        $node = $request->input('node_info');
        $data = json_decode($request->getContent(), true);
        if (!$node || $data === null) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        $this->userOnlineService->updateAliveData($data, $node->type, $node->id);
        return response()->json(['success' => true]);
    }
}
