<?php

declare(strict_types=1);

namespace LskyPro;

use LskyPro\Support\Options;

final class PostHandler
{
	private Remote $remote;
	private string $logFile;

	/**
	 * @var array<int, bool>
	 */
	private array $processing = [];

	public function __construct()
	{
		$this->remote = new Remote();
		$this->logFile = \rtrim((string) \LSKY_PRO_PLUGIN_DIR, '/\\') . '/logs/post-delete.log';

		\add_action('save_post', [$this, 'handle_post_save'], 99999, 3);
		// 仅在“永久删除 / 清空回收站”时删除图床图片。
		\add_action('before_delete_post', [$this, 'handle_post_delete'], 10, 1);
	}

	private function writeDeleteLog(string $message, array $context = []): void
	{
		$dir = \dirname($this->logFile);
		if (!\is_dir($dir)) {
			\wp_mkdir_p($dir);
		}

		$time = \date('Y-m-d H:i:s');
		$line = '[' . $time . '] ' . $message;
		if (!empty($context)) {
			$json = \wp_json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
			if (\is_string($json) && $json !== '') {
				$line .= ' ' . $json;
			}
		}
		$line .= "\n";

		@\file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
	}

	public function handle_post_delete(int $postId): void
	{
		$this->handle_post_remove($postId, 'delete');
	}

	private function handle_post_remove(int $postId, string $event): void
	{
		$this->writeDeleteLog('post_remove: start', ['event' => $event, 'post_id' => $postId]);

		if ($postId <= 0) {
			$this->writeDeleteLog('post_remove: invalid postId', ['event' => $event, 'post_id' => $postId]);
			return;
		}

		if (\wp_is_post_revision($postId)) {
			$this->writeDeleteLog('post_remove: skip revision', ['event' => $event, 'post_id' => $postId]);
			return;
		}

		$post = \get_post($postId);
		if (!($post instanceof \WP_Post) || $post->post_type !== 'post') {
			$this->writeDeleteLog('post_remove: skip non-post', ['event' => $event, 'post_id' => $postId, 'post_type' => $post instanceof \WP_Post ? $post->post_type : '']);
			return;
		}

		$optionsNorm = Options::normalized();
		$deleteRemote = !empty($optionsNorm['delete_remote_images_on_post_delete']);
		$deleteWpAttachments = !empty($optionsNorm['delete_wp_attachments_on_post_delete']);
		if (!$deleteRemote && !$deleteWpAttachments) {
			$this->writeDeleteLog('post_remove: disabled by option', ['event' => $event, 'post_id' => $postId]);
			return;
		}

		// De-dup by tracking which photo_ids were already deleted for this post.
		$deletedKey = '_lsky_pro_deleted_photo_ids';
		$deletedIds = \get_post_meta($postId, $deletedKey, true);
		if (!\is_array($deletedIds)) {
			$deletedIds = [];
		}
		$deletedIds = \array_values(\array_unique(\array_filter(\array_map('absint', $deletedIds))));
		$flagKey = '_lsky_pro_deleted_photos_on_post_remove';
		$flag = (string) \get_post_meta($postId, $flagKey, true);
		if ($flag !== '') {
			$this->writeDeleteLog('post_remove: processed flag exists, continue with diff', ['event' => $event, 'post_id' => $postId, 'flag' => $flag, 'deleted_ids' => $deletedIds]);
		}

		$map = \get_post_meta($postId, '_lsky_pro_processed_photo_ids', true);
		if (!\is_array($map)) {
			$map = [];
		}

		$ids = [];
		foreach ($map as $v) {
			if (\is_numeric($v)) {
				$id = (int) $v;
				if ($id > 0) {
					$ids[] = $id;
				}
			}
		}

		// Also delete photos for media attachments embedded in this post.
		$content = (string) \get_post_field('post_content', $postId);
		$attachmentIds = [];
		$urls = [];
		if ($content !== '') {
			if (\preg_match_all('~wp-image-(\d+)~', $content, $m1)) {
				foreach ($m1[1] as $raw) {
					$attachmentIds[] = (int) $raw;
				}
			}
			if (\preg_match_all('~data-id=["\'](\d+)["\']~', $content, $m2)) {
				foreach ($m2[1] as $raw) {
					$attachmentIds[] = (int) $raw;
				}
			}

			// Extract image-related URLs: src, data-src, data-original, data-lazy-src, srcset, href.
			$attrPatterns = [
				'~<(img|source)[^>]+src=["\']([^"\']+)["\']~i',
				'~<(img|source)[^>]+data-src=["\']([^"\']+)["\']~i',
				'~<(img|source)[^>]+data-original=["\']([^"\']+)["\']~i',
				'~<(img|source)[^>]+data-lazy-src=["\']([^"\']+)["\']~i',
				'~<(img|source)[^>]+srcset=["\']([^"\']+)["\']~i',
				'~<a[^>]+href=["\']([^"\']+)["\']~i',
			];
			foreach ($attrPatterns as $pat) {
				if (\preg_match_all($pat, $content, $mm)) {
					// srcset is group 1, others are group 2 or 1 depending on pattern.
					$col = isset($mm[2]) && \is_array($mm[2]) ? $mm[2] : (isset($mm[1]) && \is_array($mm[1]) ? $mm[1] : []);
					foreach ($col as $u) {
						$urls[] = (string) $u;
					}
				}
			}

			// srcset contains multiple candidates: "url 300w, url2 768w".
			$expanded = [];
			foreach ($urls as $u) {
				$u = \html_entity_decode((string) $u);
				if (\strpos($u, ',') !== false && \strpos($u, ' ') !== false) {
					$parts = \array_map('trim', \explode(',', $u));
					foreach ($parts as $p) {
						$first = \trim((string) \strtok($p, ' '));
						if ($first !== '') {
							$expanded[] = $first;
						}
					}
				} else {
					$expanded[] = $u;
				}
			}
			$urls = $expanded;
		}

		// Also scan post meta for URLs (many themes store image urls in custom fields / JSON).
		if (\function_exists('get_post_meta')) {
			$allMeta = \get_post_meta($postId);
			$strings = [];
			$collectStrings = static function ($value, array &$out, int $depth = 0) use (&$collectStrings): void {
				if ($depth > 3) {
					return;
				}
				if (\is_string($value)) {
					$out[] = $value;
					$maybe = \maybe_unserialize($value);
					if ($maybe !== $value) {
						$collectStrings($maybe, $out, $depth + 1);
					}
					return;
				}
				if (\is_array($value)) {
					foreach ($value as $v) {
						$collectStrings($v, $out, $depth + 1);
					}
				}
			};

			if (\is_array($allMeta)) {
				foreach ($allMeta as $k => $vals) {
					if (!\is_array($vals)) {
						continue;
					}
					foreach ($vals as $v) {
						$collectStrings($v, $strings, 0);
					}
				}
			}

			$metaUrls = [];
			foreach ($strings as $s) {
				if (!\is_string($s) || $s === '') {
					continue;
				}
				if (\preg_match_all('~https?://[^\s"\'<>]+~i', $s, $mm)) {
					foreach ($mm[0] as $u) {
						$metaUrls[] = (string) $u;
					}
				}
			}
			if (!empty($metaUrls)) {
				$urls = \array_merge($urls, $metaUrls);
			}
			$this->writeDeleteLog('post_remove: scanned meta', ['event' => $event, 'post_id' => $postId, 'meta_strings' => \count($strings), 'meta_urls' => \count($metaUrls)]);
		}

		// Include featured image.
		if (\function_exists('get_post_thumbnail_id')) {
			$thumbId = (int) \get_post_thumbnail_id($postId);
			if ($thumbId > 0) {
				$attachmentIds[] = $thumbId;
			}
		}

		$attachmentIds = \array_values(\array_unique(\array_filter(\array_map('absint', $attachmentIds))));
		foreach ($attachmentIds as $aid) {
			$pid = (int) \absint((string) \get_post_meta($aid, '_lsky_pro_photo_id', true));
			if ($pid > 0) {
				$ids[] = $pid;
			}
		}

		// Also include attachments uploaded/attached to this post (post_parent = postId).
		$childAttachmentIds = [];
		if (\function_exists('get_children')) {
			$children = \get_children([
				'post_parent' => $postId,
				'post_type' => 'attachment',
				'post_status' => 'any',
				'fields' => 'ids',
				'numberposts' => -1,
			]);
			if (\is_array($children) && !empty($children)) {
				foreach ($children as $cid) {
					$cid = (int) $cid;
					if ($cid > 0) {
						$childAttachmentIds[] = $cid;
						$pid = (int) \absint((string) \get_post_meta($cid, '_lsky_pro_photo_id', true));
						if ($pid > 0) {
							$ids[] = $pid;
						}
					}
				}
			}
		}
		$childAttachmentIds = \array_values(\array_unique(\array_filter(\array_map('absint', $childAttachmentIds))));

		// Resolve lsky URLs back to attachments via _lsky_pro_url meta, to handle cases where post HTML does not contain wp-image-XX.
		$apiUrl = isset($optionsNorm['lsky_pro_api_url']) ? (string) $optionsNorm['lsky_pro_api_url'] : '';
		$apiHost = $apiUrl !== '' ? (string) \wp_parse_url($apiUrl, \PHP_URL_HOST) : '';
		$apiPort = $apiUrl !== '' ? (string) \wp_parse_url($apiUrl, \PHP_URL_PORT) : '';

		$normalizeUrl = static function (string $u): string {
			$u = \html_entity_decode($u);
			$u = \trim($u);
			if ($u === '') {
				return '';
			}
			// Strip query/fragment.
			$parts = \wp_parse_url($u);
			if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
				return $u;
			}
			$norm = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
			if (!empty($parts['port'])) {
				$norm = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . $parts['path'];
			}
			return $norm;
		};

		$lskyUrls = [];
		foreach ($urls as $u) {
			$u = (string) $u;
			$nu = $normalizeUrl($u);
			if ($nu === '') {
				continue;
			}
			$host = (string) \wp_parse_url($nu, \PHP_URL_HOST);
			$port = (string) \wp_parse_url($nu, \PHP_URL_PORT);
			$isLsky = ($apiHost !== '' && $host === $apiHost && ($apiPort === '' || $port === $apiPort));
			if ($isLsky) {
				$lskyUrls[] = $nu;
			}
			// Local URLs -> attachment id.
			if (\function_exists('attachment_url_to_postid')) {
				$aid = (int) \attachment_url_to_postid($nu);
				if ($aid > 0) {
					$attachmentIds[] = $aid;
				}
			}
		}
		$lskyUrls = \array_values(\array_unique(\array_filter($lskyUrls)));
		$attachmentIds = \array_values(\array_unique(\array_filter(\array_map('absint', $attachmentIds))));

		if (!empty($lskyUrls) && \function_exists('get_posts')) {
			foreach ($lskyUrls as $lu) {
				$posts = \get_posts([
					'post_type' => 'attachment',
					'post_status' => 'any',
					'fields' => 'ids',
					'posts_per_page' => 20,
					'meta_key' => '_lsky_pro_url',
					'meta_value' => $lu,
				]);
				if (\is_array($posts) && !empty($posts)) {
					foreach ($posts as $aid) {
						$aid = (int) $aid;
						if ($aid > 0) {
							$attachmentIds[] = $aid;
							$pid = (int) \absint((string) \get_post_meta($aid, '_lsky_pro_photo_id', true));
							if ($pid > 0) {
								$ids[] = $pid;
							}
						}
					}
				}
			}
		}

		$attachmentIds = \array_values(\array_unique(\array_filter(\array_map('absint', $attachmentIds))));

		$this->writeDeleteLog('post_remove: discovered sources', [
			'event' => $event,
			'post_id' => $postId,
			'attachments' => $attachmentIds,
			'child_attachments' => $childAttachmentIds,
			'lsky_urls' => $lskyUrls,
		]);

		$ids = \array_values(\array_unique($ids));
		if ($deleteRemote) {
			if (empty($ids)) {
				$this->writeDeleteLog('post_remove: no photo ids found', ['event' => $event, 'post_id' => $postId]);
			} else {
				$idsToDelete = \array_values(\array_diff($ids, $deletedIds));
				if (empty($idsToDelete)) {
					$this->writeDeleteLog('post_remove: all photo ids already deleted', ['event' => $event, 'post_id' => $postId, 'photo_ids' => $ids, 'deleted_ids' => $deletedIds]);
				} else {
					$this->writeDeleteLog('post_remove: deleting photos', ['event' => $event, 'post_id' => $postId, 'photo_ids' => $idsToDelete, 'all_discovered' => $ids, 'already_deleted' => $deletedIds]);

					try {
						$uploader = new Uploader();
						$ok = $uploader->delete_photos($idsToDelete);
						if ($ok) {
							$merged = \array_values(\array_unique(\array_merge($deletedIds, $idsToDelete)));
							\update_post_meta($postId, $deletedKey, $merged);
							\update_post_meta($postId, $flagKey, $event . ':' . (string) \time());
							$this->writeDeleteLog('post_remove: delete_photos success', ['event' => $event, 'post_id' => $postId, 'count' => \count($idsToDelete), 'deleted_ids' => $merged]);
						} else {
							$this->writeDeleteLog('post_remove: delete_photos failed', ['event' => $event, 'post_id' => $postId, 'error' => (string) $uploader->getError()]);
							\error_log('LskyPro: post_remove(' . $event . ') delete_photos failed - ' . (string) $uploader->getError());
						}
					} catch (\Exception $e) {
						$this->writeDeleteLog('post_remove: exception', ['event' => $event, 'post_id' => $postId, 'error' => $e->getMessage()]);
						\error_log('LskyPro: 删除文章联动删除图床图片异常 - ' . $e->getMessage());
					}
				}
			}
		}

		if ($deleteWpAttachments && !empty($attachmentIds) && \function_exists('wp_delete_attachment')) {
			$this->writeDeleteLog('post_remove: deleting wp attachments', ['event' => $event, 'post_id' => $postId, 'attachments' => $attachmentIds]);
			$deleted = 0;
			$failed = [];
			foreach ($attachmentIds as $aid) {
				$aid = (int) $aid;
				if ($aid <= 0) {
					continue;
				}
				$res = \wp_delete_attachment($aid, true);
				if ($res instanceof \WP_Post) {
					$deleted++;
				} else {
					$failed[] = $aid;
				}
			}
			$this->writeDeleteLog('post_remove: wp attachments delete done', ['event' => $event, 'post_id' => $postId, 'deleted' => $deleted, 'failed' => $failed]);
		}
	}

	public function handle_post_save(int $postId, \WP_Post $post, bool $update): void
	{
		if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (\wp_is_post_revision($postId)) {
			return;
		}

		if ($post->post_type !== 'post') {
			return;
		}

		if (isset($this->processing[$postId])) {
			\error_log("LskyPro: 跳过正在处理中的文章 {$postId}");
			return;
		}

		$this->processing[$postId] = true;

		$postTitle = isset($post->post_title) ? (string) $post->post_title : '';
		$postTitle = \str_replace(["\r", "\n"], ' ', $postTitle);
		\error_log("LskyPro: 文章保存触发处理 - ID: {$postId}, 标题: {$postTitle}, 状态: {$post->post_status}");

		$zibOtherDataBefore = \get_post_meta($postId, 'zib_other_data', true);
		$hadZibOtherDataBefore = !empty($zibOtherDataBefore);

		$options = \get_option('lsky_pro_options');
		if (!\is_array($options) || empty($options['process_remote_images'])) {
			\error_log('LskyPro: 远程图片处理未启用');
			unset($this->processing[$postId]);
			return;
		}

		try {
			$result = $this->remote->process_post_images($postId);
			if ($result) {
				$results = $this->remote->get_results();
				$processed = isset($results['processed']) ? (int) $results['processed'] : 0;
				$failed = isset($results['failed']) ? (int) $results['failed'] : 0;

				\error_log("LskyPro: 文章 {$postId} 处理完成 - 成功: {$processed}, 失败: {$failed}");

				if ($processed > 0) {
					\add_action('admin_notices', static function () use ($processed): void {
						echo '<div class="notice notice-success is-dismissible">';
						echo '<p>LskyPro：成功处理 ' . $processed . ' 张远程图片</p>';
						echo '</div>';
					});
				}

				if ($failed > 0) {
					\add_action('admin_notices', static function () use ($failed): void {
						echo '<div class="notice notice-warning is-dismissible">';
						echo '<p>LskyPro：' . $failed . ' 张图片处理失败</p>';
						echo '</div>';
					});
				}
			} else {
				\error_log("LskyPro: 文章 {$postId} 处理失败 - " . (string) $this->remote->getError());
			}
		} catch (\Exception $e) {
			\error_log('LskyPro: 文章处理异常 - ' . $e->getMessage());
		}

		if ($hadZibOtherDataBefore) {
			$zibOtherDataAfter = \get_post_meta($postId, 'zib_other_data', true);
			if (empty($zibOtherDataAfter)) {
				\update_post_meta($postId, 'zib_other_data', $zibOtherDataBefore);
				\error_log("LskyPro: 文章 {$postId} 的 zib_other_data 被意外清空，已尝试恢复");
			}
		}

		unset($this->processing[$postId]);
	}
}
