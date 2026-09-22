<?php
/**
 * メディアファイル用ヘッダーのサーバー設定スニペット生成クラス
 *
 * メディアファイルは WordPress を経由せずサーバーが直接返すため、
 * メディア向けのヘッダーはサーバー設定（Apache / LiteSpeed の .htaccess、Nginx）で付与する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mati_Htaccess {

	/**
	 * アップロードフォルダの URL パス（Nginx 用スニペットで使用）
	 */
	public static function get_uploads_path(): string {
		$uploads = wp_upload_dir( null, false );
		$path    = wp_parse_url( $uploads['baseurl'] ?? '', PHP_URL_PATH );

		return trailingslashit( is_string( $path ) ? $path : '/wp-content/uploads' );
	}

	/**
	 * .htaccess 用の行を組み立てる
	 *
	 * @param array<string, string> $headers ヘッダー名 => 値
	 * @return string[]|null 値が安全でない場合 null
	 */
	public static function build_lines( array $headers ): ?array {
		$lines = array( '<IfModule mod_headers.c>' );

		foreach ( $headers as $name => $value ) {
			if ( ! self::is_safe_header( $name, $value ) ) {
				return null;
			}
			// mod_headers は % を書式指定として解釈するため、リテラルの % は %% にする
			$lines[] = sprintf( 'Header set %s "%s"', $name, str_replace( '%', '%%', $value ) );
		}

		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * Nginx 用スニペットを組み立てる
	 *
	 * @param array<string, string> $headers ヘッダー名 => 値
	 */
	public static function build_nginx_snippet( array $headers ): string {
		$lines = array( 'location ^~ ' . self::get_uploads_path() . ' {' );

		foreach ( $headers as $name => $value ) {
			if ( ! self::is_safe_header( $name, $value ) ) {
				return '';
			}
			$lines[] = sprintf( '    add_header %s "%s" always;', $name, $value );
		}

		$lines[] = '}';

		return implode( "\n", $lines );
	}

	/**
	 * サーバー設定に書き出してよい値か（設定ディレクティブの注入防止）
	 *
	 * 改行・制御文字・ダブルクォート・バックスラッシュ、
	 * および Nginx で変数展開される $ を含むものは拒否する。
	 */
	public static function is_safe_header( string $name, string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9-]+$/', $name )
			&& 1 === preg_match( '/^[\x20\x21\x23\x25-\x5B\x5D-\x7E]+$/', $value );
	}
}
