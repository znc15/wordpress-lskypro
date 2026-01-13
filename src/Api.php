<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\Http;

final class Api
{
	private string $apiUrl;
	private string $token;
	private string $error = '';

	public function __construct()
	{
		$options = \get_option('lsky_pro_options');
		$this->apiUrl = \is_array($options) && isset($options['lsky_pro_api_url']) ? (string) $options['lsky_pro_api_url'] : '';
		$this->token = \is_array($options) && isset($options['lsky_pro_token']) ? (string) $options['lsky_pro_token'] : '';
	}

	public function get_user_info()
	{
		return $this->make_request('GET', '/user/profile');
	}

	public function get_strategies()
	{
		return $this->get_group();
	}

	public function get_group()
	{
		return $this->make_request('GET', '/group');
	}

	private function make_request(string $method, string $endpoint)
	{
		if ($this->apiUrl === '' || $this->token === '') {
			$this->error = '未配置API地址或Token';
			return false;
		}

		$baseUrl = \rtrim($this->apiUrl, '/');
		$url = $baseUrl . $endpoint;

		$resp = Http::requestWithFallback(
			$url,
			[
				'method' => \strtoupper($method),
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Accept' => 'application/json',
				],
			]
		);

		if (\is_wp_error($resp)) {
			$this->error = $resp->get_error_message();
			return false;
		}

		$httpCode = (int) \wp_remote_retrieve_response_code($resp);
		$rawBody = (string) \wp_remote_retrieve_body($resp);

		$result = \json_decode($rawBody, true);
		if (!\is_array($result)) {
			$this->error = '响应解析失败（HTTP ' . $httpCode . '）';
			return false;
		}

		if ($httpCode !== 200) {
			$msg = isset($result['message']) ? (string) $result['message'] : ('HTTP ' . $httpCode);
			$this->error = $msg;
			return false;
		}

		if (isset($result['status']) && $result['status'] === 'success') {
			return $result;
		}

		$this->error = isset($result['message']) ? (string) $result['message'] : 'API响应异常';
		return false;
	}

	public function getError()
	{
		return $this->error;
	}
}
