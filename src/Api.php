<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Client\LskyClient;

final class Api
{
	private string $error = '';
	private LskyClient $client;

	public function __construct()
	{
		$this->client = new LskyClient();
	}

	public function get_user_info()
	{
		$res = $this->client->getUserProfileResponse();
		if ($res === false) {
			$this->error = $this->client->getError();
			return false;
		}
		return $res;
	}

	public function get_strategies()
	{
		return $this->get_group();
	}

	public function get_group()
	{
		$res = $this->client->getGroupResponse();
		if ($res === false) {
			$this->error = $this->client->getError();
			return false;
		}
		return $res;
	}

	public function getError()
	{
		return $this->error !== '' ? $this->error : $this->client->getError();
	}
}
