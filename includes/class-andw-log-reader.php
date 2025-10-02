<?php
/**
 * Log reader utility.
 *
 * @package andw-debug-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Andw_Log_Reader
 */
class Andw_Log_Reader {
    const LOG_RELATIVE_PATH  = 'debug.log';
    const DOWNLOAD_MAX_BYTES = 5242880; // 5MB.

    /**
     * Get absolute log path.
     *
     * @return string
     */
    public function get_log_path() {
        return trailingslashit( WP_CONTENT_DIR ) . self::LOG_RELATIVE_PATH;
    }

    /**
     * Fetch file stats and access state.
     *
     * @return array
     */
    public function get_stats() {
        $path   = $this->get_log_path();
        $exists = file_exists( $path );

        $info = array(
            'path'         => 'wp-content/' . self::LOG_RELATIVE_PATH,
            'exists'       => $exists,
            'readable'     => false,
            'writable'     => false,
            'size'         => 0,
            'size_human'   => size_format( 0 ),
            'modified'     => null,
            'errors'       => array(),
        );

        if ( $exists ) {
            $info['readable'] = is_readable( $path );
            $info['writable'] = is_writable( $path );

            if ( $info['readable'] ) {
                $size = filesize( $path );
                if ( false !== $size ) {
                    $info['size']       = (int) $size;
                    $info['size_human'] = size_format( $info['size'] );
                }
            } else {
                $info['errors'][] = __( 'wp-content/debug.log にアクセスできません。ファイル権限を確認してください。', 'andw-debug-viewer' );
            }

            $mtime = filemtime( $path );
            if ( false !== $mtime ) {
                $info['modified'] = (int) $mtime;
            }
        } else {
            $info['errors'][] = __( 'debug.log が見つかりません。WordPress のデバッグ設定を確認してください。', 'andw-debug-viewer' );
        }

        return $info;
    }

    /**
     * Read the latest lines from the log file.
     *
     * @param int $lines     Number of lines to return.
     * @param int $max_lines Maximum allowed lines.
     * @return array|WP_Error
     */
    public function read_tail_by_lines( $lines, $max_lines ) {
        $read_check = $this->ensure_readable();
        if ( is_wp_error( $read_check ) ) {
            return $read_check;
        }

        $lines     = max( 1, (int) $lines );
        $max_lines = max( 1, (int) $max_lines );
        $limit     = min( $lines, $max_lines );

        $path = $this->get_log_path();
        try {
            $file = new SplFileObject( $path, 'r' );
            $file->setFlags( SplFileObject::DROP_NEW_LINE );
            $file->seek( PHP_INT_MAX );
            $last_line = $file->key();
        } catch ( Exception $exception ) {
            return new WP_Error( 'andw_read_failed', __( 'ログファイルを開けませんでした。', 'andw-debug-viewer' ) );
        }

        $buffer = array();
        for ( $line_number = $last_line; $line_number >= 0 && count( $buffer ) < $limit; --$line_number ) {
            $file->seek( $line_number );
            $buffer[] = (string) $file->current();
        }

        $buffer = array_reverse( $buffer );

        return array(
            'content'        => implode( PHP_EOL, $buffer ),
            'total_lines'    => count( $buffer ),
            'scanned_lines'  => count( $buffer ),
            'fetched_method' => 'lines',
            'fallback'       => false,
        );
    }

    /**
     * Read the log filtered by minutes.
     *
     * @param int $minutes   Minutes to look back.
     * @param int $max_lines Maximum allowed lines to scan.
     * @return array|WP_Error
     */
    public function read_tail_by_minutes( $minutes, $max_lines ) {
        $minutes = max( 1, (int) $minutes );

        $scan = $this->read_tail_by_lines( $max_lines, $max_lines );
        if ( is_wp_error( $scan ) ) {
            return $scan;
        }

        $threshold = current_time( 'timestamp' ) - ( $minutes * MINUTE_IN_SECONDS );
        $lines     = '' === $scan['content'] ? array() : explode( "
", $scan['content'] );
        $output    = array();
        $fallback  = false;

        foreach ( $lines as $line ) {
            $timestamp = $this->extract_timestamp( $line );
            if ( null === $timestamp ) {
                $fallback = true;
            }
            if ( null === $timestamp || $timestamp >= $threshold ) {
                $output[] = $line;
            }
        }

        if ( $fallback && empty( $output ) ) {
            $output = $lines;
        }

        return array(
            'content'        => implode( PHP_EOL, $output ),
            'total_lines'    => count( $output ),
            'scanned_lines'  => isset( $scan['scanned_lines'] ) ? (int) $scan['scanned_lines'] : count( $lines ),
            'fetched_method' => 'minutes',
            'fallback'       => $fallback,
        );
    }

    /**
     * Clear the log file.
     *
     * @return true|WP_Error
     */
    public function clear_log() {
        $write_check = $this->ensure_writable();
        if ( is_wp_error( $write_check ) ) {
            return $write_check;
        }

        $path   = $this->get_log_path();
        $handle = fopen( $path, 'c+b' );

        if ( ! $handle ) {
            return new WP_Error( 'andw_open_failed', __( 'ログファイルを開けませんでした。', 'andw-debug-viewer' ) );
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );
            return new WP_Error( 'andw_lock_failed', __( 'ログファイルをロックできませんでした。', 'andw-debug-viewer' ) );
        }

        $truncated = ftruncate( $handle, 0 );
        fflush( $handle );
        flock( $handle, LOCK_UN );
        fclose( $handle );

        if ( ! $truncated ) {
            return new WP_Error( 'andw_truncate_failed', __( 'ログファイルをクリアできませんでした。', 'andw-debug-viewer' ) );
        }

        return true;
    }

    /**
     * Prepare download payload.
     *
     * @return array|WP_Error
     */
    public function get_download_payload() {
        $read_check = $this->ensure_readable();
        if ( is_wp_error( $read_check ) ) {
            return $read_check;
        }

        $path = $this->get_log_path();
        $size = filesize( $path );

        if ( false === $size ) {
            return new WP_Error( 'andw_size_failed', __( 'ログサイズを取得できませんでした。', 'andw-debug-viewer' ) );
        }

        if ( $size > self::DOWNLOAD_MAX_BYTES ) {
            return new WP_Error( 'andw_download_too_large', __( 'ログが大きすぎます。5MB未満のみダウンロードできます。', 'andw-debug-viewer' ) );
        }

        $content = file_get_contents( $path );
        if ( false === $content ) {
            return new WP_Error( 'andw_download_failed', __( 'ログを読み込めませんでした。', 'andw-debug-viewer' ) );
        }

        return array(
            'filename' => 'debug.log',
            'content'  => $content,
            'size'     => (int) $size,
        );
    }

    /**
     * Ensure the file can be read.
     *
     * @return true|WP_Error
     */
    private function ensure_readable() {
        $path = $this->get_log_path();
        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'andw_missing', __( 'debug.log が存在しません。', 'andw-debug-viewer' ) );
        }
        if ( ! is_readable( $path ) ) {
            return new WP_Error( 'andw_not_readable', __( 'debug.log を読み取れません。ファイル権限を確認してください。', 'andw-debug-viewer' ) );
        }

        return true;
    }

    /**
     * Ensure the file can be written.
     *
     * @return true|WP_Error
     */
    private function ensure_writable() {
        $path = $this->get_log_path();
        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'andw_missing', __( 'debug.log が存在しません。', 'andw-debug-viewer' ) );
        }
        if ( ! is_writable( $path ) ) {
            return new WP_Error( 'andw_not_writable', __( 'debug.log に書き込めません。ファイル権限を確認してください。', 'andw-debug-viewer' ) );
        }

        return true;
    }

    /**
     * Extract timestamp from a log line if possible.
     *
     * @param string $line Log line.
     * @return int|null
     */
    private function extract_timestamp( $line ) {
        if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches ) ) {
            $timestamp = strtotime( $matches[1] );
            if ( false !== $timestamp ) {
                return (int) $timestamp;
            }
        }

        return null;
    }
}
