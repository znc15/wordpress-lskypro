<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\Http;
use LskyPro\Support\Options;
use LskyPro\Uploader\ImageTrait;
use LskyPro\Uploader\LoggingTrait;
use LskyPro\Uploader\RequestContextTrait;
use LskyPro\Uploader\RequirementsTrait;

class Uploader
{
	use LoggingTrait;
	use RequirementsTrait;
	use ImageTrait;
	use RequestContextTrait;

	private string $api_url = '';
	private string $token = '';
	private string $error = '';
	private string $log_dir = '';

	/** @var array<string, mixed> */
	private array $requirements = [];

	/** @var array<string, mixed> */
	private array $last_request_context = [];

	private ?int $last_uploaded_photo_id = null;

	/** @var array<string, mixed> */
	private array $upload_log_context = [];

	public function __construct()
	{
		$options = Options::normalized();
		$this->api_url = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$this->token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		$this->log_dir = \rtrim((string) \LSKY_PRO_PLUGIN_DIR, '/\\') . '/logs';
		$this->initializeLogDirectory();

		$this->checkRequirements();
	}

	/**
	 * 设置上传日志上下文（例如：由哪篇文章触发）。
	 */
	public function setUploadLogContext($context): void
	{
		$this->upload_log_context = \is_array($context) ? $context : [];
	}

	public function clearUploadLogContext(): void
	{
		$this->upload_log_context = [];
	}

	public function setUploadLogContextFromPost($post_id, $source = ''): void
	{
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			$this->upload_log_context = [];
			return;
		}

		$title = '';
		$url = '';
		if (\function_exists('get_the_title')) {
			$title = (string) \get_the_title($post_id);
		}
		if (\function_exists('get_permalink')) {
			$url = (string) \get_permalink($post_id);
		}

		$this->upload_log_context = [
			'trigger' => 'post',
			'source' => \is_string($source) ? $source : '',
			'post_id' => $post_id,
			'post_title' => $title,
			'post_url' => $url,
		];
	}

	public function get_user_info()
	{
		$options = Options::normalized();
		$apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		if ($apiUrl === '' || $token === '') {
			$this->error = '请先配置 API 地址和 Token';
			return false;
		}

		$response = Http::requestWithFallback(
			\rtrim($apiUrl, '/') . '/user/profile',
			[
				'method' => 'GET',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/json',
				],
			]
		);

		if (\is_wp_error($response)) {
			$this->error = $response->get_error_message();
			return false;
		}

		$body = \json_decode((string) \wp_remote_retrieve_body($response), true);
		if (!\is_array($body)) {
			$this->error = '获取用户信息失败：响应解析失败';
			return false;
		}

		if (!isset($body['status']) || $body['status'] !== 'success') {
			$this->error = isset($body['message']) ? (string) $body['message'] : '获取用户信息失败';
			return false;
		}

		return $body['data'] ?? false;
	}

	public function getError()
	{
		return $this->error;
	}

	public function get_group_info()
	{
		$options = Options::normalized();
		$apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		if ($apiUrl === '' || $token === '') {
			$this->error = '请先配置 API 地址和 Token';
			return false;
		}

		$cacheKey = 'lsky_pro_group_' . \md5($apiUrl . '|' . $token);
		$cached = \get_transient($cacheKey);
		if (\is_array($cached)) {
			return $cached;
		}

		$response = Http::requestWithFallback(
			\rtrim($apiUrl, '/') . '/group',
			[
				'method' => 'GET',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/json',
				],
			]
		);

		if (\is_wp_error($response)) {
			$this->error = $response->get_error_message();
			return false;
		}

		$data = \json_decode((string) \wp_remote_retrieve_body($response), true);
		if (!\is_array($data)) {
			$this->error = '获取组信息失败：响应解析失败';
			return false;
		}

		if (!isset($data['status']) || $data['status'] !== 'success') {
			$this->error = isset($data['message']) ? (string) $data['message'] : '获取组信息失败';
			return false;
		}

		\set_transient($cacheKey, $data, 10 * \MINUTE_IN_SECONDS);
		return $data;
	}

	public function get_allowed_file_types(): array
	{
		$group = $this->get_group_info();
		if ($group === false) {
			return [];
		}

		$types = $group['data']['group']['options']['allow_file_types'] ?? [];
		if (!\is_array($types)) {
			return [];
		}

		$normalized = [];
		foreach ($types as $t) {
			$t = \strtolower(\trim((string) $t));
			if ($t !== '') {
				$normalized[] = $t;
			}
		}

		return \array_values(\array_unique($normalized));
	}

	public function get_max_upload_size_bytes(): int
	{
		$group = $this->get_group_info();
		if ($group === false) {
			return 0;
		}

		$kb = (int) ($group['data']['group']['options']['max_upload_size'] ?? 0);
		if ($kb <= 0) {
			return 0;
		}

		return $kb * 1024;
	}

	/**
	 * @return array{storage_id:int,album_id:int,matched:bool}
	 */
	protected function applyKeywordRules(string $basename, int $storageId, int $albumId): array
	{
		$basename = \strtolower(\trim($basename));
		if ($basename === '') {
			return [
				'storage_id' => $storageId,
				'album_id' => $albumId,
				'matched' => false,
			];
		}

		$options = Options::normalized();
		$rules = $options['keyword_routing_rules'] ?? [];
		if (!\is_array($rules)) {
			$rules = [];
		}

		foreach ($rules as $rule) {
			if (!\is_array($rule)) {
				continue;
			}

			$keywords = $rule['keywords'] ?? [];
			if (!\is_array($keywords)) {
				continue;
			}

			foreach ($keywords as $keyword) {
				$keyword = \strtolower(\trim((string) $keyword));
				if ($keyword === '') {
					continue;
				}
				if (\strpos($basename, $keyword) === false) {
					continue;
				}

				$ruleStorageId = (int) \absint((string) ($rule['storage_id'] ?? 0));
				if ($ruleStorageId > 0) {
					$storageId = $ruleStorageId;
				}

				$ruleAlbumId = (int) \absint((string) ($rule['album_id'] ?? 0));
				if ($ruleAlbumId > 0) {
					$albumId = $ruleAlbumId;
				}

				return [
					'storage_id' => $storageId,
					'album_id' => $albumId,
					'matched' => true,
				];
			}
		}

		return [
			'storage_id' => $storageId,
			'album_id' => $albumId,
			'matched' => false,
		];
	}

	public function upload($file_path, string $source_url = '')
	{
		if ($this->api_url === '' || $this->token === '') {
			$this->error = '未配置API地址或Token';
			$this->logError($file_path, $this->error, $this->upload_log_context);
			return false;
		}

		$this->last_uploaded_photo_id = null;

		$options = Options::normalized();
		$currentUserId = \function_exists('get_current_user_id') ? (int) \get_current_user_id() : 0;
		$storage_id = Options::resolveStorageIdForUser($currentUserId, $options);
		$album_id = Options::resolveAlbumIdForUser($currentUserId, $options);

		$basename = \basename((string) $file_path);
		if ($source_url !== '') {
			$path = \parse_url($source_url, \PHP_URL_PATH);
			if (\is_string($path)) {
				$fromUrl = \basename($path);
				if ($fromUrl !== '') {
					$basename = $fromUrl;
				}
			}
		}
		$ruleResult = $this->applyKeywordRules($basename, (int) $storage_id, (int) $album_id);
		$storage_id = $ruleResult['storage_id'];
		$album_id = $ruleResult['album_id'];
		$ruleMatched = $ruleResult['matched'];

		$storages = $this->get_strategies();
		if (\is_array($storages) && !empty($storages)) {
			$allowed_ids = [];
			foreach ($storages as $s) {
				if (\is_array($s) && isset($s['id'])) {
					$allowed_ids[] = (int) $s['id'];
				}
			}

			$allowed_ids = \array_values(\array_unique(\array_filter($allowed_ids)));
			if (!empty($allowed_ids)) {
				if ($storage_id <= 0 || !\in_array($storage_id, $allowed_ids, true)) {
					$storage_id = (int) $allowed_ids[0];
				}
			}
		}

		if ($storage_id <= 0) {
			$storage_id = 1;
		}

		$this->logRouting($basename, $ruleMatched, (int) $storage_id, (int) $album_id, $this->upload_log_context);

		$image_info = $this->checkImageFile($file_path);
		if ($image_info === false) {
			$this->debug_log('图片检查失败: ' . $this->error);
			$this->logError($file_path, $this->error, $this->upload_log_context);
			return false;
		}

		$this->logImageInfo($file_path, $image_info);

		$cfile = new \CURLFile((string) $file_path, (string) $image_info['mime_type'], \basename((string) $file_path));

		$upload_url = \rtrim($this->api_url, '/') . '/upload';
		$is_public = true;

		$this->setUploadRequestContext([
			'url' => $upload_url,
			'method' => 'POST',
			'trigger' => $this->upload_log_context,
			'fields' => [
				'storage_id' => (int) $storage_id,
				'is_public' => $is_public ? 1 : 0,
				'album_id' => $album_id > 0 ? (int) $album_id : null,
				'token_present' => 1,
			],
			'file' => [
				'name' => \basename((string) $file_path),
				'mime' => (string) $image_info['mime_type'],
				'size' => (int) $image_info['size'],
				'width' => (int) $image_info['width'],
				'height' => (int) $image_info['height'],
			],
		]);

		$post_data = [
			'file' => $cfile,
			'storage_id' => $storage_id,
			'is_public' => $is_public ? '1' : '0',
		];
		if ($album_id > 0) {
			$post_data['album_id'] = (string) $album_id;
		}

		$this->debug_log('准备发送请求到: ' . $upload_url);

		$response = false;
		$err = '';
		$curl_errno = 0;
		$http_code = 0;
		$curl_info = [];

		$max_attempts = (int) \apply_filters('lsky_pro_upload_curl_attempts', 3);
		if ($max_attempts < 1) {
			$max_attempts = 1;
		}

		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
			$curl = \curl_init();

			\curl_setopt_array($curl, [
				\CURLOPT_URL => $upload_url,
				\CURLOPT_RETURNTRANSFER => true,
				\CURLOPT_ENCODING => 'gzip,deflate',
				\CURLOPT_MAXREDIRS => 10,
				\CURLOPT_TIMEOUT => 60,
				\CURLOPT_CONNECTTIMEOUT => 10,
				\CURLOPT_FOLLOWLOCATION => true,
				\CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
				\CURLOPT_CUSTOMREQUEST => 'POST',
				\CURLOPT_POSTFIELDS => $post_data,
				\CURLOPT_HTTPHEADER => [
					'Accept: application/json',
					'User-Agent: WordPress/LskyPro-Uploader',
					'Authorization: Bearer ' . (string) $this->token,
					'Expect:',
				],
				\CURLOPT_SSL_VERIFYPEER => $this->should_verify_ssl(),
				\CURLOPT_SSL_VERIFYHOST => $this->should_verify_ssl() ? 2 : 0,
				\CURLOPT_VERBOSE => (\defined('WP_DEBUG') && \WP_DEBUG),
			]);

			$response = \curl_exec($curl);
			$curl_errno = (int) \curl_errno($curl);
			$err = (string) \curl_error($curl);
			$http_code = (int) \curl_getinfo($curl, \CURLINFO_HTTP_CODE);
			$curl_info = (array) \curl_getinfo($curl);

			\curl_close($curl);

			if ($curl_errno === 0 && $err === '') {
				break;
			}

			$retryable_errno = [56, 55, 52, 28, 7, 35];
			$should_retry = \in_array($curl_errno, $retryable_errno, true);
			if (!$should_retry || $attempt >= $max_attempts) {
				break;
			}

			\usleep($attempt === 1 ? 300000 : 800000);
		}

		$this->debug_log('请求响应状态码: ' . $http_code);
		$this->debug_log('请求响应内容(截断): ' . \substr((string) $response, 0, 800));

		if ($curl_errno !== 0 || $err !== '') {
			$this->error = 'CURL错误: ' . ($err !== '' ? $err : ('errno=' . $curl_errno));

			$info_subset = [
				'url' => $curl_info['url'] ?? '',
				'http_code' => $curl_info['http_code'] ?? $http_code,
				'content_type' => $curl_info['content_type'] ?? '',
				'primary_ip' => $curl_info['primary_ip'] ?? '',
				'primary_port' => $curl_info['primary_port'] ?? '',
				'local_ip' => $curl_info['local_ip'] ?? '',
				'local_port' => $curl_info['local_port'] ?? '',
				'ssl_verify_result' => $curl_info['ssl_verify_result'] ?? null,
				'http_version' => $curl_info['http_version'] ?? null,
				'redirect_count' => $curl_info['redirect_count'] ?? null,
				'total_time' => $curl_info['total_time'] ?? null,
				'namelookup_time' => $curl_info['namelookup_time'] ?? null,
				'connect_time' => $curl_info['connect_time'] ?? null,
				'appconnect_time' => $curl_info['appconnect_time'] ?? null,
				'starttransfer_time' => $curl_info['starttransfer_time'] ?? null,
			];

			$info_json = \wp_json_encode($info_subset, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
			if (!\is_string($info_json)) {
				$info_json = '';
			}

			$this->logError(
				$file_path,
				$this->error . \sprintf('；errno=%d；请求：%s；curlinfo：%s', $curl_errno, $upload_url, \substr($info_json, 0, 800)),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			return false;
		}

		if ($response === false || $response === '') {
			$this->error = '服务器没有响应';
			$this->logError(
				$file_path,
				$this->error . \sprintf('；请求：%s；HTTP：%d', $upload_url, $http_code),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			return false;
		}

		$response_snippet = \substr((string) $response, 0, 500);

		$result = \json_decode((string) $response, true);
		if (\json_last_error() !== \JSON_ERROR_NONE || !\is_array($result)) {
			$this->error = '无效的API响应格式';
			$this->logError(
				$file_path,
				$this->error . \sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			return false;
		}

		if ($http_code !== 200) {
			$error_message = isset($result['message']) ? (string) $result['message'] : '未知错误';
			$this->error = \sprintf('HTTP错误(%d): %s', $http_code, $error_message);
			$this->logError(
				$file_path,
				$this->error . \sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			return false;
		}

		if (!isset($result['status']) || $result['status'] !== 'success') {
			$this->error = '上传失败: ' . (isset($result['message']) ? (string) $result['message'] : '未知错误');
			$this->logError(
				$file_path,
				$this->error . \sprintf('；请求：%s；HTTP：%d；响应片段：%s', $upload_url, $http_code, $response_snippet),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			return false;
		}

		$image_url = '';

		$origin = '';
		$api_parts = \wp_parse_url((string) $this->api_url);
		if (\is_array($api_parts) && !empty($api_parts['scheme']) && !empty($api_parts['host'])) {
			$origin = $api_parts['scheme'] . '://' . $api_parts['host'];
			if (!empty($api_parts['port'])) {
				$origin .= ':' . $api_parts['port'];
			}
		}

		$normalize_url = static function ($candidate) use ($origin): string {
			if (!\is_string($candidate)) {
				return '';
			}
			$candidate = \trim($candidate);
			if ($candidate === '') {
				return '';
			}
			if (\preg_match('#^https?://#i', $candidate)) {
				return $candidate;
			}
			if ($origin !== '' && \strpos($candidate, '/') === 0) {
				return $origin . $candidate;
			}
			return '';
		};

		if (isset($result['data']['links']) && \is_array($result['data']['links'])) {
			$preferred_keys = ['url', 'original_url', 'raw_url', 'public_url', 'direct_url', 'download_url', 'html'];
			foreach ($preferred_keys as $k) {
				if (!\array_key_exists($k, $result['data']['links'])) {
					continue;
				}
				$candidate = $normalize_url($result['data']['links'][$k]);
				if ($candidate !== '') {
					$image_url = $candidate;
					break;
				}
			}

			if ($image_url === '') {
				foreach ($result['data']['links'] as $v) {
					$candidate = $normalize_url($v);
					if ($candidate !== '') {
						$image_url = $candidate;
						break;
					}
				}
			}
		}

		if ($image_url === '' && isset($result['data']['url'])) {
			$image_url = $normalize_url($result['data']['url']);
		}
		if ($image_url === '' && isset($result['data']['public_url'])) {
			$image_url = $normalize_url($result['data']['public_url']);
		}

		$image_url = (string) \apply_filters('lsky_pro_uploaded_image_url', $image_url, $result);

		$photo_id = $result['data']['id'] ?? null;
		if ($photo_id === null && isset($result['data']['photo']['id'])) {
			$photo_id = $result['data']['photo']['id'];
		}
		if ($photo_id !== null && \is_numeric($photo_id)) {
			$photo_id = (int) $photo_id;
			if ($photo_id > 0) {
				$this->last_uploaded_photo_id = $photo_id;
			}
		}

		if ($image_url === '') {
			$this->error = '无法获取图片URL';

			$data_keys = '';
			if (isset($result['data']) && \is_array($result['data'])) {
				$data_keys = \implode(',', \array_map('strval', \array_keys($result['data'])));
			}
			$links_keys = '';
			if (isset($result['data']['links']) && \is_array($result['data']['links'])) {
				$links_keys = \implode(',', \array_map('strval', \array_keys($result['data']['links'])));
			}

			$this->logError(
				$file_path,
				$this->error . \sprintf('；请求：%s；响应片段：%s', $upload_url, $response_snippet),
				$this->upload_log_context
			);
			$ctx = $this->formatLastRequestContextForError();
			if ($ctx !== '') {
				$this->error .= '；请求参数：' . $ctx;
			}
			if ($data_keys !== '') {
				$this->error .= '；data字段：' . $data_keys;
			}
			if ($links_keys !== '') {
				$this->error .= '；links字段：' . $links_keys;
			}
			$this->error .= '；响应片段：' . $response_snippet;
			return false;
		}

		$this->logSuccess(\basename((string) $file_path), $image_url, $this->upload_log_context);
		$this->debug_log('上传成功，图片URL: ' . $image_url);

		return $image_url;
	}

	public function getLastUploadedPhotoId()
	{
		return $this->last_uploaded_photo_id;
	}

	public function delete_photos($photo_ids)
	{
		if (!\is_array($photo_ids)) {
			$photo_ids = [$photo_ids];
		}

		$ids = [];
		foreach ($photo_ids as $id) {
			$id = (int) \absint($id);
			if ($id > 0) {
				$ids[] = $id;
			}
		}

		$ids = \array_values(\array_unique($ids));
		if (empty($ids)) {
			return true;
		}

		if ($this->api_url === '' || $this->token === '') {
			$this->error = '未配置API地址或Token';
			return false;
		}

		$url = \rtrim($this->api_url, '/') . '/user/photos';
		$body = \wp_json_encode($ids, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
		if (!\is_string($body)) {
			$body = '[]';
		}

		$response = Http::requestWithFallback(
			$url,
			[
				'method' => 'DELETE',
				'headers' => [
					'Authorization' => 'Bearer ' . (string) $this->token,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body' => $body,
			]
		);

		if (\is_wp_error($response)) {
			$this->error = $response->get_error_message();
			return false;
		}

		$http_code = (int) \wp_remote_retrieve_response_code($response);
		if ($http_code === 204) {
			return true;
		}

		if ($http_code >= 200 && $http_code < 300) {
			return true;
		}

		$this->error = '删除失败，HTTP ' . $http_code;
		return false;
	}

	public function get_strategies()
	{
		$options = Options::normalized();
		$apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		if ($apiUrl === '' || $token === '') {
			$this->error = '请先配置 API 地址和 Token';
			return false;
		}

		$group = $this->get_group_info();
		if ($group === false) {
			return false;
		}

		$storages = $group['data']['storages'] ?? null;
		if (!\is_array($storages)) {
			return [];
		}

		return $storages;
	}

	public function get_albums($page = 1, $per_page = 100, $q = null)
	{
		$options = Options::normalized();
		$apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		if ($apiUrl === '' || $token === '') {
			$this->error = '请先配置 API 地址和 Token';
			return false;
		}

		$page = (int) $page;
		if ($page <= 0) {
			$page = 1;
		}

		$per_page = (int) $per_page;
		if ($per_page <= 0) {
			$per_page = 100;
		}

		$q = \is_string($q) ? \trim($q) : '';

		$cache_key = 'lsky_pro_albums_' . \md5($apiUrl . '|' . $token . '|' . $page . '|' . $per_page . '|' . $q);
		$cached = \get_transient($cache_key);
		if (\is_array($cached)) {
			return $cached;
		}

		$url = \rtrim($apiUrl, '/') . '/user/albums';
		$args = [
			'page' => $page,
			'per_page' => $per_page,
		];
		if ($q !== '') {
			$args['q'] = $q;
		}
		$url = \add_query_arg($args, $url);

		$response = Http::requestWithFallback(
			$url,
			[
				'method' => 'GET',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/json',
				],
			]
		);

		if (\is_wp_error($response)) {
			$this->error = $response->get_error_message();
			return false;
		}

		$http_code = (int) \wp_remote_retrieve_response_code($response);
		$raw_body = (string) \wp_remote_retrieve_body($response);

		$data = \json_decode($raw_body, true);
		if (!\is_array($data)) {
			$snippet = \substr(\trim($raw_body), 0, 300);
			$this->error = '获取相册列表失败：响应解析失败（HTTP ' . $http_code . '）' . ($snippet !== '' ? '；响应片段：' . $snippet : '');
			return false;
		}

		if ($http_code !== 200) {
			$msg = isset($data['message']) ? (string) $data['message'] : ('HTTP ' . $http_code);
			$this->error = '获取相册列表失败：' . $msg;
			return false;
		}

		if (!isset($data['status']) || $data['status'] !== 'success') {
			$this->error = isset($data['message']) ? (string) $data['message'] : '获取相册列表失败';
			return false;
		}

		\set_transient($cache_key, $data, 10 * \MINUTE_IN_SECONDS);
		return $data;
	}

	public function get_all_albums($q = null, $per_page = 100)
	{
		$options = Options::normalized();
		$apiUrl = isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$token = isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';

		if ($apiUrl === '' || $token === '') {
			$this->error = '请先配置 API 地址和 Token';
			return false;
		}

		$per_page = (int) $per_page;
		if ($per_page <= 0) {
			$per_page = 100;
		}

		$q = \is_string($q) ? \trim($q) : '';
		$cache_key = 'lsky_pro_albums_all_' . \md5($apiUrl . '|' . $token . '|' . $per_page . '|' . $q);
		$cached = \get_transient($cache_key);
		if (\is_array($cached)) {
			return $cached;
		}

		$first = $this->get_albums(1, $per_page, $q);
		if ($first === false) {
			return false;
		}

		$extract_items = static function ($resp): array {
			if (!\is_array($resp) || !isset($resp['data']) || !\is_array($resp['data'])) {
				return [];
			}
			if (isset($resp['data']['data']) && \is_array($resp['data']['data'])) {
				return $resp['data']['data'];
			}
			$data = $resp['data'];
			$is_list = \array_keys($data) === \range(0, \count($data) - 1);
			if ($is_list) {
				return $data;
			}
			if (isset($resp['data']['albums']) && \is_array($resp['data']['albums'])) {
				return $resp['data']['albums'];
			}
			return [];
		};

		$extract_last_page = static function ($resp): int {
			if (!\is_array($resp) || !isset($resp['data']) || !\is_array($resp['data'])) {
				return 1;
			}
			if (isset($resp['data']['meta']['last_page'])) {
				return (int) $resp['data']['meta']['last_page'];
			}
			if (isset($resp['data']['last_page'])) {
				return (int) $resp['data']['last_page'];
			}
			return 1;
		};

		$albums = $extract_items($first);
		$last_page = $extract_last_page($first);
		if ($last_page < 1) {
			$last_page = 1;
		}

		$max_pages = 50;
		if ($last_page > $max_pages) {
			$last_page = $max_pages;
		}

		for ($p = 2; $p <= $last_page; $p++) {
			$resp = $this->get_albums($p, $per_page, $q);
			if ($resp === false) {
				return false;
			}

			$page_albums = $extract_items($resp);
			if (!empty($page_albums)) {
				$albums = \array_merge($albums, $page_albums);
			}
		}

		if (empty($albums)) {
			$has_data = (\is_array($first) && isset($first['data']) && \is_array($first['data']));
			if ($has_data) {
				$keys = \implode(',', \array_keys($first['data']));
				$this->error = '未获取到任何相册（data keys：' . $keys . '）';
			} else {
				$this->error = '未获取到任何相册（返回结构异常）';
			}

			\set_transient($cache_key, $albums, 60);
			return $albums;
		}

		\set_transient($cache_key, $albums, 10 * \MINUTE_IN_SECONDS);
		return $albums;
	}

	public function getApiUrl(): string
	{
		$options = Options::normalized();
		return isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
	}

	public function getToken(): string
	{
		$options = Options::normalized();
		return isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';
	}
}
