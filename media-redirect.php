<?php
/**
 * Plugin Name: Media 404 Redirect (uploads root -> dated folders) incl. thumbnails
 */

class Media404Redirect {

	private string $base_path = '/src/files/';
	private int $cache_ttl = DAY_IN_SECONDS * 14;

	public function __construct() {
		add_action('template_redirect', [$this, 'handle_redirect'], 0);
	}

	/**
	 * Hauptmethode für den Redirect-Handler
	 */
	public function handle_redirect(): void {
		if (!is_404()) {
			return;
		}

		$path = $this->get_request_path();
		if (!$path || !$this->is_eligible_path($path)) {
			return;
		}

		$filename = basename($path);
		if (!$this->is_media_file($filename)) {
			return;
		}

		// Cache-Check
		$cache_key = $this->get_cache_key($filename);
		if ($cached_url = get_transient($cache_key)) {
			$this->do_redirect($cached_url);
		}

		// 1. Direkter Versuch über Dateinamen
		$attachment_id = $this->find_attachment_by_filename($filename);
		if ($attachment_id) {
			$url = $this->resolve_url_for_attachment($attachment_id, $filename);
			if ($url) {
				$this->cache_and_redirect($cache_key, $url);
			}
		}

		// 2. Thumbnail-Fallback: Falls foo-512x512.jpg, suche nach foo.jpg
		if ($this->is_thumbnail_pattern($filename, $matches)) {
			$original_filename = $matches[1] . '.' . $matches[4];
			$attachment_id = $this->find_attachment_by_filename($original_filename);

			if ($attachment_id) {
				// Versuche über Metadaten
				$url = $this->resolve_url_for_attachment($attachment_id, $filename);
				if ($url) {
					$this->cache_and_redirect($cache_key, $url);
				}

				// Letzter Versuch: Gleiches Verzeichnis wie das Original raten
				$url = $this->guess_url_from_original($attachment_id, $filename);
				if ($url) {
					$this->cache_and_redirect($cache_key, $url, DAY_IN_SECONDS * 2);
				}
			}
		}
	}

	private function get_request_path(): ?string {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		return parse_url($uri, PHP_URL_PATH);
	}

	private function is_eligible_path(string $path): bool {
		// Muss Basis-Pfad enthalten
		if (stripos($path, $this->base_path) === false) {
			return false;
		}

		// Darf nicht schon im Zielschema (/YYYY/MM/) sein
		if (preg_match('~' . preg_quote($this->base_path, '~') . '\d{4}/\d{2}/~i', $path)) {
			return false;
		}

		return true;
	}

	private function is_media_file(string $filename): bool {
		return (bool) preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf)$/i', $filename);
	}

	private function get_cache_key(string $filename): string {
		return 'media_redirect_' . md5(strtolower($filename));
	}

	private function find_attachment_by_filename(string $filename): int {
		global $wpdb;
		$like = '%' . $wpdb->esc_like('/' . $filename);
		
		$attachment_id = (int) $wpdb->get_var($wpdb->prepare("
			SELECT post_id 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = '_wp_attached_file' 
			  AND meta_value LIKE %s 
			ORDER BY post_id DESC 
			LIMIT 1
		", $like));

		// Fallback für -scaled Bilder
		if (!$attachment_id && preg_match('/^(.*)\.(jpg|jpeg|png)$/i', $filename, $m)) {
			$scaled_filename = $m[1] . '-scaled.' . $m[2];
			$like_scaled = '%' . $wpdb->esc_like('/' . $scaled_filename);
			$attachment_id = (int) $wpdb->get_var($wpdb->prepare("
				SELECT post_id 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = '_wp_attached_file' 
				  AND meta_value LIKE %s 
				ORDER BY post_id DESC 
				LIMIT 1
			", $like_scaled));
		}

		return $attachment_id;
	}

	private function resolve_url_for_attachment(int $attachment_id, string $wanted_filename): ?string {
		$meta = wp_get_attachment_metadata($attachment_id);
		if (!$meta) {
			return null;
		}

		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (!$attached_file) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		$base_url = trailingslashit($uploads['baseurl']);
		$dir = trailingslashit(dirname($attached_file));

		// 1. Original prüfen
		if (isset($meta['file']) && basename($meta['file']) === $wanted_filename) {
			return $base_url . $meta['file'];
		}

		// 2. Größen (Sizes) prüfen
		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $size) {
				if (!empty($size['file']) && $size['file'] === $wanted_filename) {
					return $base_url . $dir . $size['file'];
				}
			}
		}

		return null;
	}

	private function is_thumbnail_pattern(string $filename, &$matches): bool {
		return (bool) preg_match('/^(.*)-(\d+)x(\d+)\.(jpg|jpeg|png|gif|webp)$/i', $filename, $matches);
	}

	private function guess_url_from_original(int $attachment_id, string $filename): ?string {
		$attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
		if (!$attached_file) {
			return null;
		}

		$uploads = wp_get_upload_dir();
		$dir = trailingslashit(dirname($attached_file));
		
		return trailingslashit($uploads['baseurl']) . $dir . $filename;
	}

	private function cache_and_redirect(string $key, string $url, ?int $ttl = null): void {
		set_transient($key, $url, $ttl ?? $this->cache_ttl);
		$this->do_redirect($url);
	}

	private function do_redirect(string $url): void {
		wp_redirect($url, 301);
		exit;
	}
}

new Media404Redirect();
