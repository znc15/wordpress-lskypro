<?php

declare(strict_types=1);

namespace LskyPro;

final class PostHandler
{
	private Remote $remote;

	/**
	 * @var array<int, bool>
	 */
	private array $processing = [];

	public function __construct()
	{
		$this->remote = new Remote();

		\add_action('save_post', [$this, 'handle_post_save'], 99999, 3);
		\add_action('before_delete_post', [$this, 'handle_post_delete'], 10, 1);
	}

	public function handle_post_delete(int $postId): void
	{
		if ($postId <= 0) {
			return;
		}

		if (\wp_is_post_revision($postId)) {
			return;
		}

		$post = \get_post($postId);
		if (!($post instanceof \WP_Post) || $post->post_type !== 'post') {
			return;
		}

		$options = \get_option('lsky_pro_options');
		$enabled = \is_array($options) && !empty($options['delete_remote_images_on_post_delete']);
		if (!$enabled) {
			return;
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
		if ($content !== '') {
			$attachmentIds = [];
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
			$attachmentIds = \array_values(\array_unique(\array_filter(\array_map('absint', $attachmentIds))));
			foreach ($attachmentIds as $aid) {
				$pid = (int) \absint((string) \get_post_meta($aid, '_lsky_pro_photo_id', true));
				if ($pid > 0) {
					$ids[] = $pid;
				}
			}
		}

		$ids = \array_values(\array_unique($ids));
		if (empty($ids)) {
			return;
		}

		try {
			$uploader = new Uploader();
			$uploader->delete_photos($ids);
			// Clean up mapping to avoid orphan meta in edge cases.
			\delete_post_meta($postId, '_lsky_pro_processed_photo_ids');
		} catch (\Exception $e) {
			\error_log('LskyPro: 删除文章联动删除图床图片异常 - ' . $e->getMessage());
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
