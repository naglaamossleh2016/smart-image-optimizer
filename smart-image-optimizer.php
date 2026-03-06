<?php
/**
 * Plugin Name: Smart Image Optimizer
 * Description: AI rename · resize · delete unused · smart categorize — all in one
 * Version:     4.0.0
 * Author:      naglaa mossleh
 */
if (!defined('ABSPATH'))
    exit;
define('SIO_VER', '4.0.0');
define('SIO_URL', plugin_dir_url(__FILE__));

class SmartImageOptimizer
{
    private string $opt = 'sio_settings';

    public function __construct()
    {
        add_action('init', ['SmartImageOptimizer', 'reg_tax']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        foreach ([
            'sio_get_images',
            'sio_get_stats',
            'sio_save_settings',
            'sio_ai_name',
            'sio_rename_single',
            'sio_resize_single',
            'sio_bulk',
            'sio_scan_unused',
            'sio_delete_unused',
            'sio_scan_cats',
            'sio_apply_cats',
            'sio_apply_cat_folders',
            'sio_scan_refs',
            'sio_fix_refs',
        ] as $a)
            add_action("wp_ajax_{$a}", [$this, "ajax_{$a}"]);
    }

    /* ── Taxonomy for media folders ── */
    public static function reg_tax()
    {
        register_taxonomy('media_cat', 'attachment', [
            'label' => 'Media Category',
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'rewrite' => false,
            'hierarchical' => true,
        ]);
    }

    /* ── Menu ── */
    public function menu()
    {
        add_media_page(
            'Smart Image Optimizer',
            '🖼 Smart Optimizer',
            'manage_options',
            'smart-image-optimizer',
            [$this, 'page']
        );
    }

    /* ── Scripts ── */
    public function enqueue($hook)
    {
        if (strpos($hook, 'smart-image-optimizer') === false)
            return;
        wp_enqueue_media();
        wp_enqueue_style('sio-css', SIO_URL . 'assets/style.css', [], SIO_VER);
        wp_enqueue_script('sio-js', SIO_URL . 'assets/app.js', ['jquery'], SIO_VER, true);
        $s = $this->cfg();
        wp_localize_script('sio-js', 'SIO', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sio_nonce'),
            'has_key' => !empty($s['groq_key']) || !empty($s['api_key']) || !empty($s['gemini_key']),
        ]);
    }

    /* ── Settings ── */
    private function cfg(): array
    {
        return wp_parse_args(get_option($this->opt, []), [
            'ai_provider' => 'groq',
            'groq_key' => '',
            'gemini_key' => '',
            'api_key' => '',
            'ai_model' => 'claude-haiku-4-5-20251001',
            'ai_language' => 'en',
            'ai_context' => '',
        ]);
    }

    public function ajax_sio_save_settings()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        update_option($this->opt, [
            'ai_provider' => sanitize_text_field($_POST['ai_provider'] ?? 'groq'),
            'groq_key' => sanitize_text_field($_POST['groq_key'] ?? ''),
            'gemini_key' => sanitize_text_field($_POST['gemini_key'] ?? ''),
            'api_key' => sanitize_text_field($_POST['api_key'] ?? ''),
            'ai_model' => sanitize_text_field($_POST['ai_model'] ?? 'claude-haiku-4-5-20251001'),
            'ai_language' => sanitize_text_field($_POST['ai_language'] ?? 'en'),
            'ai_context' => sanitize_text_field($_POST['ai_context'] ?? ''),
        ]);
        wp_send_json_success('Saved');
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — IMAGES
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_get_images()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $page = max(1, intval($_POST['page'] ?? 1));
        $pp = max(1, intval($_POST['per_page'] ?? 24));
        $src = sanitize_text_field($_POST['search'] ?? '');
        $flt = sanitize_text_field($_POST['filter'] ?? 'all');
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => $pp,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        if ($src)
            $args['s'] = $src;
        $q = new WP_Query($args);
        $out = [];
        foreach ($q->posts as $p) {
            $f = get_attached_file($p->ID);
            $meta = wp_get_attachment_metadata($p->ID);
            $fn = basename($f ?? '');
            $kb = ($f && file_exists($f)) ? round(filesize($f) / 1024, 1) : 0;
            $bad = $this->bad_name($fn);
            if ($flt === 'bad' && !$bad)
                continue;
            if ($flt === 'large' && $kb < 300)
                continue;
            $out[] = [
                'id' => $p->ID,
                'filename' => $fn,
                'thumb' => wp_get_attachment_image_url($p->ID, 'thumbnail'),
                'width' => $meta['width'] ?? 0,
                'height' => $meta['height'] ?? 0,
                'size_kb' => $kb,
                'bad_name' => $bad
            ];
        }
        wp_send_json_success(['images' => $out, 'total' => $q->found_posts, 'total_pages' => $q->max_num_pages]);
    }

    public function ajax_sio_get_stats()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $t = wp_count_attachments();
        $cnt = ($t->{'image/jpeg'} ?? 0) + ($t->{'image/png'} ?? 0) + ($t->{'image/gif'} ?? 0) + ($t->{'image/webp'} ?? 0);
        $ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 300, 'fields' => 'ids', 'post_status' => 'inherit']);
        $bad = 0;
        foreach ($ids as $id)
            if (($f = get_attached_file($id)) && $this->bad_name(basename($f)))
                $bad++;
        wp_send_json_success(['total' => $cnt, 'bad' => $bad, 'sample' => count($ids)]);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — AI NAME SINGLE
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_ai_name()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $id = intval($_POST['id']);
        $f = get_attached_file($id);
        if (!$f || !file_exists($f))
            wp_send_json_error('File not found');
        $s = $this->cfg();
        $slug = $this->ai_call($f, $s['ai_context'] ?? '', $s['ai_language'] ?? 'en', 'name');
        is_wp_error($slug) ? wp_send_json_error($slug->get_error_message())
            : wp_send_json_success(['slug' => $slug, 'suggested' => $slug . '-' . $id]);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — RENAME / RESIZE SINGLE
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_rename_single()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $id = intval($_POST['id']);
        $nm = sanitize_file_name(sanitize_text_field($_POST['new_name'] ?? ''));
        if (!$id || !$nm)
            wp_send_json_error('Missing data');
        $r = $this->do_rename($id, $nm);
        is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success($r);
    }

    public function ajax_sio_resize_single()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $id = intval($_POST['id'] ?? 0);
        $w = intval($_POST['width'] ?? 0);
        $h = intval($_POST['height'] ?? 0);
        $m = sanitize_text_field($_POST['mode'] ?? 'max_width');
        if (!$id || (!$w && !$h))
            wp_send_json_error('Missing data');
        $r = $this->do_resize($id, $w, $h, $m);
        is_wp_error($r) ? wp_send_json_error($r->get_error_message()) : wp_send_json_success($r);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — BULK (rename | resize | both)
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_bulk()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        @set_time_limit(300);

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $op = sanitize_text_field($_POST['operation'] ?? 'rename'); // rename|resize|both
        $pfx = sanitize_text_field($_POST['prefix'] ?? '');
        $maxw = intval($_POST['max_width'] ?? 1920);
        $maxh = intval($_POST['max_height'] ?? 0);
        $rm = sanitize_text_field($_POST['resize_mode'] ?? 'max_width');
        if (empty($ids))
            wp_send_json_error('No images selected');

        $s = $this->cfg();
        $ctx = $s['ai_context'] ?? '';
        $lng = $s['ai_language'] ?? 'en';
        $out = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'replaced' => 0, 'details' => []];

        foreach ($ids as $id) {
            $f = get_attached_file($id);
            if (!$f || !file_exists($f)) {
                $out['skipped']++;
                $out['details'][] = "⏭ #{$id}: file not found";
                continue;
            }
            $fn = basename($f);
            $detail = "#{$id} [{$fn}]";
            try {
                if ($op === 'rename' || $op === 'both') {
                    $slug = $this->ai_call($f, $ctx, $lng, 'name');
                    if (is_wp_error($slug)) {
                        $out['failed']++;
                        $out['details'][] = "❌ {$detail}: " . $slug->get_error_message();
                        continue;
                    }
                    $nm = ($pfx ? sanitize_title($pfx) . '-' : '') . $slug . '-' . $id;
                    $ren = $this->do_rename($id, $nm);
                    if (is_wp_error($ren)) {
                        $out['failed']++;
                        $out['details'][] = "❌ {$detail}: " . $ren->get_error_message();
                        continue;
                    }
                    $out['replaced'] += $ren['replaced'];
                    $seo = $ren['seo_title'] ?? '';
                    $detail .= " → <strong>{$ren['new_filename']}</strong> | 🏷 alt: <em>{$seo}</em> | 🔗{$ren['replaced']} links";
                }
                if ($op === 'resize' || $op === 'both') {
                    $res = $this->do_resize($id, $maxw, $maxh, $rm);
                    if (is_wp_error($res)) {
                        $out['failed']++;
                        $out['details'][] = "❌ {$detail}: " . $res->get_error_message();
                        continue;
                    }
                    if ($res['skipped']) {
                        // For resize-only: count skipped images separately
                        if ($op === 'resize') {
                            $out['skipped']++;
                            $out['details'][] = "⏭ {$detail}: {$res['reason']}";
                            continue;
                        }
                        // For 'both': rename succeeded, resize just skipped — still a success
                        $detail .= " | ⏭ resize skipped ({$res['reason']})";
                    } else {
                        $detail .= " | 📐{$res['old_w']}×{$res['old_h']}→{$res['new_w']}×{$res['new_h']} (-{$res['saved_kb']}KB)";
                    }
                }
                $out['success']++;
                $out['details'][] = "✅ {$detail}";
            } catch (\Exception $e) {
                $out['failed']++;
                $out['details'][] = "❌ #{$id}: " . $e->getMessage();
            }
        }
        wp_send_json_success($out);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — UNUSED IMAGES
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_scan_unused()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        $ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids']);
        $unused = [];
        foreach ($ids as $id) {
            if (!$this->is_unused($id))
                continue;
            $f = get_attached_file($id);
            $kb = ($f && file_exists($f)) ? round(filesize($f) / 1024, 1) : 0;
            $unused[] = ['id' => $id, 'filename' => basename($f ?? ''), 'thumb' => wp_get_attachment_image_url($id, 'thumbnail'), 'size_kb' => $kb, 'url' => wp_get_attachment_url($id)];
        }
        wp_send_json_success(['unused' => $unused, 'count' => count($unused), 'total_mb' => round(array_sum(array_column($unused, 'size_kb')) / 1024, 2)]);
    }

    public function ajax_sio_delete_unused()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $del = $fail = 0;
        foreach ($ids as $id) {
            if ($this->is_unused($id)) {
                wp_delete_attachment($id, true) ? $del++ : $fail++;
            }
        }
        wp_send_json_success(['deleted' => $del, 'failed' => $fail]);
    }

    private function is_unused(int $id): bool
    {
        global $wpdb;
        $f = get_attached_file($id);
        if (!$f)
            return false;
        $fn = basename($f);
        if (!$fn)
            return false;
        // featured image?
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%d", $id)))
            return false;
        $lk = '%' . $wpdb->esc_like($fn) . '%';
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status!='inherit'", $lk)))
            return false;
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key!='_thumbnail_id'", $lk)))
            return false;
        return true;
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — AI CATEGORIZATION
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_scan_cats()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        @set_time_limit(300);
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (empty($ids))
            wp_send_json_error('No images selected');
        $s = $this->cfg();
        $ctx = $s['ai_context'] ?? '';
        $lng = $s['ai_language'] ?? 'en';
        $cats = [];
        $details = [];
        foreach ($ids as $id) {
            $f = get_attached_file($id);
            if (!$f || !file_exists($f))
                continue;
            $cat = $this->ai_call($f, $ctx, $lng, 'category');
            if (is_wp_error($cat)) {
                $details[] = "❌ #{$id}: " . $cat->get_error_message();
                continue;
            }
            $cats[$cat][] = $id;
            $details[] = "📁 #{$id} [" . basename($f) . "] → <strong>{$cat}</strong>";
        }
        wp_send_json_success(['categories' => $cats, 'details' => $details]);
    }

    public function ajax_sio_apply_cats()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        $cats = json_decode(stripslashes($_POST['categories'] ?? '{}'), true);
        $move = (bool) ($_POST['move_files'] ?? true); // physically move files to subfolders
        $applied = 0;
        $moved = 0;
        $failed = [];
        $ud = wp_upload_dir();

        foreach ($cats as $slug => $ids) {
            // 1. Register taxonomy term
            $term = term_exists($slug, 'media_cat');
            if (!$term)
                $term = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'media_cat', ['slug' => $slug]);
            if (is_wp_error($term))
                continue;
            $tid = is_array($term) ? $term['term_id'] : $term;

            // 2. Create physical subfolder inside uploads/categories/{slug}/
            $cat_dir = $ud['basedir'] . '/categories/' . $slug;
            if (!file_exists($cat_dir))
                wp_mkdir_p($cat_dir);

            foreach ((array) $ids as $id) {
                $id = intval($id);
                wp_set_object_terms($id, $tid, 'media_cat');
                $applied++;

                if (!$move)
                    continue;

                // 3. Move file + all thumbnails to category folder
                $old_file = get_attached_file($id);
                if (!$old_file || !file_exists($old_file))
                    continue;

                $old_dir = dirname($old_file);
                $filename = basename($old_file);
                $new_file = $cat_dir . '/' . $filename;

                // Avoid collision
                if ($old_file === $new_file)
                    continue;
                if (file_exists($new_file)) {
                    $info = pathinfo($filename);
                    $filename = $info['filename'] . '-' . $id . '.' . $info['extension'];
                    $new_file = $cat_dir . '/' . $filename;
                }

                // Move main file
                if (!@rename($old_file, $new_file)) {
                    $failed[] = "#{$id}: could not move";
                    continue;
                }

                // Move thumbnails
                $meta = wp_get_attachment_metadata($id);
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $sk => $sd) {
                        $ot = $old_dir . '/' . $sd['file'];
                        $nt = $cat_dir . '/' . $sd['file'];
                        if (file_exists($ot))
                            @rename($ot, $nt);
                    }
                }

                // Build new URL and relative path
                $new_url = $ud['baseurl'] . '/categories/' . $slug . '/' . $filename;
                $new_rel = 'categories/' . $slug . '/' . $filename;
                $old_url = wp_get_attachment_url($id);
                $old_rel = get_post_meta($id, '_wp_attached_file', true);

                // Update DB
                update_attached_file($id, $new_file);
                update_post_meta($id, '_wp_attached_file', $new_rel);
                wp_update_post(['ID' => $id, 'guid' => $new_url]);

                // Update references sitewide (old basename → new basename in new URL)
                $old_fn = basename($old_file);
                if ($old_fn !== $filename) {
                    $this->db_replace_map([$old_fn => $filename]);
                }
                // Update full URL references
                if ($old_url !== $new_url) {
                    $this->db_replace_map([$old_url => $new_url]);
                }

                $moved++;
            }
        }

        wp_cache_flush();
        wp_send_json_success([
            'applied' => $applied,
            'moved' => $moved,
            'failed' => count($failed),
            'errors' => array_slice($failed, 0, 5),
            'cats' => count($cats),
        ]);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — MOVE FILES TO REAL SUBFOLDERS BY CATEGORY
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_apply_cat_folders()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();

        // categories = { 'dental': [1,2,3], 'hair': [4,5] }
        $cats = json_decode(stripslashes($_POST['categories'] ?? '{}'), true);
        $results = ['moved' => 0, 'failed' => 0, 'details' => []];
        $ud = wp_upload_dir();

        foreach ($cats as $slug => $ids) {
            $folder_name = sanitize_file_name(strtolower(str_replace(' ', '-', $slug)));
            // Create subfolder inside uploads base dir (e.g. /wp-content/uploads/dental/)
            $target_dir = trailingslashit($ud['basedir']) . $folder_name;
            $target_url = trailingslashit($ud['baseurl']) . $folder_name;

            if (!file_exists($target_dir) && !wp_mkdir_p($target_dir)) {
                $results['details'][] = "❌ Could not create folder: {$folder_name}";
                $results['failed'] += count($ids);
                continue;
            }

            foreach ((array) $ids as $id) {
                $id = intval($id);
                $file = get_attached_file($id);
                if (!$file || !file_exists($file)) {
                    $results['failed']++;
                    $results['details'][] = "❌ #{$id}: file not found";
                    continue;
                }

                $old_url = wp_get_attachment_url($id);
                $fn = basename($file);
                $new_file = $target_dir . '/' . $fn;
                $new_url = $target_url . '/' . $fn;

                // Already in this folder?
                if (realpath(dirname($file)) === realpath($target_dir)) {
                    $results['details'][] = "⏭ #{$id} [{$fn}]: already in /{$folder_name}/";
                    continue;
                }

                // Move thumbnails too
                $meta = wp_get_attachment_metadata($id);
                $old_dir = dirname($file);
                $url_map = [];

                // Move main file
                if (!@rename($file, $new_file)) {
                    $results['failed']++;
                    $results['details'][] = "❌ #{$id}: could not move {$fn}";
                    continue;
                }
                $url_map[basename($old_url)] = $folder_name . '/' . $fn;

                // Move thumbnail sizes
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $sk => &$sd) {
                        $old_thumb = $old_dir . '/' . $sd['file'];
                        $new_thumb = $target_dir . '/' . $sd['file'];
                        if (file_exists($old_thumb)) {
                            @rename($old_thumb, $new_thumb);
                            $url_map[$sd['file']] = $folder_name . '/' . $sd['file'];
                        }
                    }
                }

                // Update WordPress metadata
                update_attached_file($id, $new_file);
                if ($meta) {
                    $meta['file'] = str_replace($ud['basedir'] . '/', '', $new_file);
                    wp_update_attachment_metadata($id, $meta);
                }
                wp_update_post(['ID' => $id, 'guid' => $new_url]);

                // Update all DB references (full URL swap)
                $full_map = [];
                foreach ($url_map as $old_fn => $new_rel) {
                    $old_full = trailingslashit($ud['baseurl']) . ltrim($old_fn, '/');
                    // old_fn might already be just basename
                    $old_base = basename($old_fn);
                    $new_full = $target_url . '/' . basename($new_rel);
                    $full_map[$old_full] = $new_full;
                }
                // Also update paths that include year/month subdirs
                $old_path_fragment = str_replace($ud['basedir'] . '/', '', $file);
                $new_path_fragment = str_replace($ud['basedir'] . '/', '', $new_file);
                $full_map[$ud['baseurl'] . '/' . $old_path_fragment] = $ud['baseurl'] . '/' . $new_path_fragment;

                $replaced = $this->db_replace_map($full_map);

                // Save taxonomy term
                $term = term_exists($folder_name, 'media_cat');
                if (!$term)
                    $term = wp_insert_term(ucwords(str_replace('-', ' ', $folder_name)), 'media_cat', ['slug' => $folder_name]);
                if (!is_wp_error($term)) {
                    $tid = is_array($term) ? $term['term_id'] : $term;
                    wp_set_object_terms($id, intval($tid), 'media_cat');
                }

                $results['moved']++;
                $results['details'][] = "✅ #{$id} [{$fn}] → <strong>/{$folder_name}/</strong> (links: {$replaced})";
            }
        }

        wp_cache_flush();
        wp_send_json_success($results);
    }

    /* ════════════════════════════════════════════════════════════
       AJAX — AUDIT
    ════════════════════════════════════════════════════════════ */
    public function ajax_sio_scan_refs()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        $fn = sanitize_text_field($_POST['filename'] ?? '');
        if (!$fn)
            wp_send_json_error('Filename required');
        $refs = $this->find_refs($fn);
        wp_send_json_success(['refs' => $refs, 'total' => array_sum(array_column($refs, 'count'))]);
    }
    public function ajax_sio_fix_refs()
    {
        check_ajax_referer('sio_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error();
        $o = sanitize_text_field($_POST['old'] ?? '');
        $n = sanitize_text_field($_POST['new'] ?? '');
        if (!$o || !$n)
            wp_send_json_error('Missing data');
        wp_send_json_success(['replaced' => $this->db_replace_map([$o => $n])]);
    }

    /* ════════════════════════════════════════════════════════════
       AI CORE
    ════════════════════════════════════════════════════════════ */
    private function ai_call(string $file, string $ctx, string $lang, string $task)
    {
        $s = $this->cfg();
        $p = $s['ai_provider'] ?? 'groq';
        return match ($p) {
            'anthropic' => $this->ai_anthropic($file, $ctx, $lang, $task),
            'gemini' => $this->ai_gemini($file, $ctx, $lang, $task),
            default => $this->ai_groq($file, $ctx, $lang, $task),
        };
    }

    private function build_prompt(string $ctx, string $lang, string $task): string
    {
        $lp = match ($lang) { 'ar' => 'Output in English relevant to Arabic-speaking markets.', 'auto' => 'Use the most SEO-appropriate English.', default => 'Use English only.'};
        $cp = $ctx ? "\nSite context: {$ctx}" : '';
        if ($task === 'category')
            return "Look at this image. Assign ONE short category label (1-2 words, lowercase, hyphens).\nExamples: products, food, people, nature, architecture, fashion, vehicles, technology, documents\nReply with ONLY the category slug.{$cp}";
        return "Analyze this image. Generate a short SEO-friendly filename slug.\n- 2-5 words, lowercase, hyphens only\n- Describe what you SEE (subject, color, material)\n- No generic words (image, photo, file)\n- No dates or numbers\n- {$lp}{$cp}\nGood examples: red-leather-wallet, blue-cotton-shirt, grilled-salmon-plate\nReply with ONLY the slug.";
    }

    private function slug(string $raw)
    {
        $s = trim(strtolower($raw));
        $s = preg_replace('/[^a-z\-]/', '', $s);
        $s = preg_replace('/-{2,}/', '-', $s);
        $s = trim($s, '-');
        return ($s && strlen($s) >= 2) ? $s : new \WP_Error('bad', 'Invalid AI response: ' . $raw);
    }

    private function prep_image(string $file)
    {
        $em = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $em[$ext] ?? null;
        if (!$mime)
            return new \WP_Error('unsupported', "Unsupported: {$ext}");
        $ed = wp_get_image_editor($file);
        if (!is_wp_error($ed)) {
            $sz = $ed->get_size();
            if (($sz['width'] ?? 0) > 1024 || ($sz['height'] ?? 0) > 1024) {
                $ed->resize(1024, 1024, false);
                $tmp = sys_get_temp_dir() . '/sio_' . uniqid() . '.' . $ext;
                $sv = $ed->save($tmp);
                if (!is_wp_error($sv) && file_exists($tmp)) {
                    $d = base64_encode(file_get_contents($tmp));
                    @unlink($tmp);
                    return ['data' => $d, 'mime' => $mime];
                }
            }
        }
        return ['data' => base64_encode(file_get_contents($file)), 'mime' => $mime];
    }

    private function ai_groq(string $f, string $ctx, string $lang, string $task)
    {
        $s = $this->cfg();
        $key = trim($s['groq_key'] ?? '');
        if (!$key)
            return new \WP_Error('no_key', 'Groq API key missing — go to Settings');
        $img = $this->prep_image($f);
        if (is_wp_error($img))
            return $img;
        $body = wp_json_encode([
            'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
            'max_tokens' => 60,
            'temperature' => 0.2,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$img['mime']};base64,{$img['data']}"]],
                        ['type' => 'text', 'text' => $this->build_prompt($ctx, $lang, $task)],
                    ]
                ]
            ]
        ]);
        $r = wp_remote_post('https://api.groq.com/openai/v1/chat/completions', ['timeout' => 30, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => "Bearer {$key}"], 'body' => $body]);
        if (is_wp_error($r))
            return new \WP_Error('api', 'Groq failed: ' . $r->get_error_message());
        $code = wp_remote_retrieve_response_code($r);
        $json = json_decode(wp_remote_retrieve_body($r), true);
        if ($code !== 200)
            return new \WP_Error('api', 'Groq error: ' . ($json['error']['message'] ?? "HTTP {$code}"));
        return $this->slug($json['choices'][0]['message']['content'] ?? '');
    }

    private function ai_gemini(string $f, string $ctx, string $lang, string $task)
    {
        $s = $this->cfg();
        $key = trim($s['gemini_key'] ?? '');
        if (!$key)
            return new \WP_Error('no_key', 'Gemini API key missing — go to Settings');
        $img = $this->prep_image($f);
        if (is_wp_error($img))
            return $img;
        $body = wp_json_encode(['contents' => [['parts' => [['inline_data' => ['mime_type' => $img['mime'], 'data' => $img['data']]], ['text' => $this->build_prompt($ctx, $lang, $task)]]]], 'generationConfig' => ['maxOutputTokens' => 60, 'temperature' => 0.2]]);
        foreach (['gemini-1.5-flash-8b', 'gemini-1.5-flash', 'gemini-2.0-flash-lite', 'gemini-2.0-flash'] as $model) {
            $r = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", ['timeout' => 30, 'headers' => ['Content-Type' => 'application/json'], 'body' => $body]);
            if (is_wp_error($r))
                continue;
            $code = wp_remote_retrieve_response_code($r);
            $json = json_decode(wp_remote_retrieve_body($r), true);
            if ($code === 200)
                return $this->slug($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
            if ($code === 429) {
                sleep(2);
                continue;
            }
        }
        return new \WP_Error('api', 'All Gemini models failed — try Groq instead');
    }

    private function ai_anthropic(string $f, string $ctx, string $lang, string $task)
    {
        $s = $this->cfg();
        $key = trim($s['api_key'] ?? '');
        if (!$key)
            return new \WP_Error('no_key', 'Anthropic API key missing — go to Settings');
        $img = $this->prep_image($f);
        if (is_wp_error($img))
            return $img;
        $body = wp_json_encode([
            'model' => $s['ai_model'] ?? 'claude-haiku-4-5-20251001',
            'max_tokens' => 60,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]],
                        ['type' => 'text', 'text' => $this->build_prompt($ctx, $lang, $task)],
                    ]
                ]
            ]
        ]);
        $r = wp_remote_post('https://api.anthropic.com/v1/messages', ['timeout' => 30, 'headers' => ['Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01'], 'body' => $body]);
        if (is_wp_error($r))
            return new \WP_Error('api', 'Anthropic failed: ' . $r->get_error_message());
        $code = wp_remote_retrieve_response_code($r);
        $json = json_decode(wp_remote_retrieve_body($r), true);
        if ($code !== 200)
            return new \WP_Error('api', 'Anthropic error: ' . ($json['error']['message'] ?? "HTTP {$code}"));
        return $this->slug($json['content'][0]['text'] ?? '');
    }

    /* ════════════════════════════════════════════════════════════
       CORE — RENAME
    ════════════════════════════════════════════════════════════ */
    private function do_rename(int $id, string $new_name)
    {
        $old = get_attached_file($id);
        if (!$old || !file_exists($old))
            return new \WP_Error('nf', 'File not found on server');
        $dir = dirname($old);
        $ext = strtolower(pathinfo($old, PATHINFO_EXTENSION));
        $os = pathinfo($old, PATHINFO_FILENAME);
        $ns = trim(preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', sanitize_file_name($new_name)), '-');
        $ud = wp_upload_dir();
        $ou = wp_get_attachment_url($id);
        $rm = [];
        $um = [];
        $ns2 = $this->uniq_stem($dir, $ns, $ext, $old);
        $nfn = $ns2 . '.' . $ext;
        $nf = $dir . '/' . $nfn;
        if ($nf !== $old) {
            $rm[$old] = $nf;
            $um[basename($old)] = $nfn;
        }
        $meta = wp_get_attachment_metadata($id);
        $nsz = [];
        if (!empty($meta['sizes']))
            foreach ($meta['sizes'] as $sk => $sd) {
                $ot = $dir . '/' . $sd['file'];
                if (!file_exists($ot))
                    continue;
                $te = strtolower(pathinfo($sd['file'], PATHINFO_EXTENSION));
                $suf = preg_replace('/^' . preg_quote($os, '/') . '/', '', $pf = pathinfo($sd['file'], PATHINFO_FILENAME));
                $nt = $ns2 . $suf . '.' . $te;
                $rm[$ot] = $dir . '/' . $nt;
                $um[$sd['file']] = $nt;
                $nsz[$sk] = array_merge($sd, ['file' => $nt]);
            }
        foreach ($rm as $from => $to)
            if ($from !== $to && !@rename($from, $to))
                return new \WP_Error('rf', 'Cannot rename: ' . basename($from));
        $nu = str_replace(basename($old), $nfn, $ou);
        update_attached_file($id, $nf);

        // Convert slug to human-readable title: "red-leather-wallet-234" → "Red Leather Wallet"
        $clean_slug = preg_replace('/-\d+$/', '', $ns2); // strip trailing ID
        $human_title = ucwords(str_replace('-', ' ', $clean_slug));

        // Build a descriptive SEO caption/description from the slug words
        $words = array_filter(explode('-', $clean_slug));
        $description = implode(' ', $words); // plain words for description field

        wp_update_post([
            'ID' => $id,
            'post_title' => $human_title,    // Media library title
            'post_name' => sanitize_title($ns2),
            'post_excerpt' => $human_title,    // Caption — shown in galleries & shortcodes
            'post_content' => $human_title,    // Description — indexed by search engines
            'guid' => $nu,
        ]);

        // Alt text — #1 SEO factor for images
        update_post_meta($id, '_wp_attachment_image_alt', $human_title);

        if ($meta) {
            $meta['file'] = str_replace($ud['basedir'] . '/', '', $nf);
            foreach ($nsz as $k => $v)
                $meta['sizes'][$k] = $v;
            wp_update_attachment_metadata($id, $meta);
        }
        return ['new_filename' => $nfn, 'new_url' => $nu, 'replaced' => $this->db_replace_map($um), 'seo_title' => $human_title];
    }

    /* ════════════════════════════════════════════════════════════
       CORE — RESIZE
    ════════════════════════════════════════════════════════════ */
    private function do_resize(int $id, int $w, int $h, string $mode)
    {
        $f = get_attached_file($id);
        if (!$f || !file_exists($f))
            return new \WP_Error('nf', 'File not found');
        $meta = wp_get_attachment_metadata($id);
        $ow = $meta['width'] ?? 0;
        $oh = $meta['height'] ?? 0;

        // No skipping — allow both upscale and downscale in all modes

        $ed = wp_get_image_editor($f);
        if (is_wp_error($ed))
            return $ed;
        $ob = filesize($f);

        // WordPress resize() requires both w and h as integers > 0
        // We calculate the missing dimension manually using the original ratio
        $ratio = $oh > 0 ? ($ow / $oh) : 1;

        if ($mode === 'fixed_height') {
            $target_h = max(1, $h);
            $target_w = max(1, intval(round($target_h * $ratio)));
        } elseif ($mode === 'max_width') {
            $target_w = max(1, $w);
            $target_h = max(1, intval(round($target_w / $ratio)));
        } elseif ($mode === 'crop') {
            $target_w = max(1, $w);
            $target_h = max(1, $h);
        } else {
            $target_w = max(1, $w ?: intval(round($h * $ratio)));
            $target_h = max(1, $h ?: intval(round($w / $ratio)));
        }

        // Use GD directly to avoid WP_Image_Editor dimension quirks
        $src = @imagecreatefromstring(file_get_contents($f));
        if (!$src)
            return new \WP_Error('gd', 'Cannot open image with GD');

        $dst = imagecreatetruecolor($target_w, $target_h);

        // Preserve transparency for PNG
        if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $trans = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $target_w, $target_h, $trans);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_w, $target_h, $ow, $oh);

        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $saved = false;
        if ($ext === 'png')
            $saved = imagepng($dst, $f, 8);
        elseif ($ext === 'webp')
            $saved = imagewebp($dst, $f, 85);
        else
            $saved = imagejpeg($dst, $f, 85);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved)
            return new \WP_Error('gd_save', 'GD could not save the image');

        // Read actual dimensions from the saved file — never trust cache
        $actual = @getimagesize($f);
        $new_w = $actual ? $actual[0] : $target_w;
        $new_h = $actual ? $actual[1] : $target_h;
        $nb = filesize($f);

        // Update WordPress metadata with real dimensions
        $meta['width'] = $new_w;
        $meta['height'] = $new_h;
        $meta['filesize'] = $nb;
        // Clear thumbnail sizes so they get regenerated with correct base
        $meta['sizes'] = [];
        wp_update_attachment_metadata($id, $meta);
        wp_cache_delete($id, 'post_meta');

        return [
            'skipped' => false,
            'old_w' => $ow,
            'old_h' => $oh,
            'new_w' => $new_w,
            'new_h' => $new_h,
            'old_kb' => round($ob / 1024, 1),
            'new_kb' => round($nb / 1024, 1),
            'saved_kb' => round(($ob - $nb) / 1024, 1),
        ];
    }

    /* ════════════════════════════════════════════════════════════
       DB HELPERS
    ════════════════════════════════════════════════════════════ */
    private function db_replace_map(array $map): int
    {
        global $wpdb;
        $base = trailingslashit(wp_upload_dir()['baseurl']);
        $pairs = [];
        foreach ($map as $o => $n) {
            if ($o === $n)
                continue;
            $pairs[$o] = $n;
            $pairs[$base . $o] = $base . $n;
        }
        if (empty($pairs))
            return 0;
        $total = 0;
        $skip = ['rewrite_rules', 'cron', 'auth_key', 'secure_auth_key'];
        $ph = implode(',', array_fill(0, count($skip), '%s'));
        foreach ($pairs as $o => $n) {
            $lk = '%' . $wpdb->esc_like($o) . '%';
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content=REPLACE(post_content,%s,%s) WHERE post_content LIKE %s", $o, $n, $lk));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_excerpt=REPLACE(post_excerpt,%s,%s) WHERE post_excerpt LIKE %s", $o, $n, $lk));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET guid=REPLACE(guid,%s,%s) WHERE guid LIKE %s AND post_type='attachment'", $o, $n, $lk));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=REPLACE(meta_value,%s,%s) WHERE meta_value LIKE %s", $o, $n, $lk));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=REPLACE(option_value,%s,%s) WHERE option_value LIKE %s AND option_name NOT IN($ph)", ...array_merge([$o, $n, $lk], $skip)));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->termmeta} SET meta_value=REPLACE(meta_value,%s,%s) WHERE meta_value LIKE %s", $o, $n, $lk));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_value=REPLACE(meta_value,%s,%s) WHERE meta_value LIKE %s", $o, $n, $lk));
        }
        wp_cache_flush();
        return $total;
    }

    private function find_refs(string $fn): array
    {
        global $wpdb;
        $lk = '%' . $wpdb->esc_like($fn) . '%';
        $refs = [];
        foreach ([
            'posts' => "SELECT ID,post_title FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status!='inherit' LIMIT 20",
            'postmeta' => "SELECT post_id as ID,meta_key as post_title FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 20",
            'options' => "SELECT option_id as ID,option_name as post_title FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 10"
        ] as $t => $sql) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $lk));
            if ($rows)
                $refs[] = ['table' => $t, 'count' => count($rows), 'rows' => array_map(fn($r) => "#{$r->ID} — {$r->post_title}", array_slice($rows, 0, 5))];
        }
        return $refs;
    }

    private function uniq_stem(string $dir, string $stem, string $ext, string $keep = ''): string
    {
        if (!file_exists("$dir/$stem.$ext") || "$dir/$stem.$ext" === $keep)
            return $stem;
        for ($i = 1; file_exists("$dir/{$stem}-{$i}.{$ext}"); $i++)
            ;
        return "{$stem}-{$i}";
    }

    private function bad_name(string $fn): bool
    {
        $n = strtolower(pathinfo($fn, PATHINFO_FILENAME));
        foreach (['/^img[-_]?\d+/', '/^dsc[fn]?[-_]?\d+/', '/^photo[-_]?\d+/', '/^pic[-_]?\d+/', '/^image[-_]?\d+/', '/^pxl[-_]?\d+/', '/^mvimg[-_]?\d+/', '/^whatsapp/', '/^\d{4}[-_]\d{2}[-_]\d{2}/', '/^\d{8,}$/', '/^screenshot/', '/^capture[-_]?\d+/', '/^[0-9a-f]{8,}$/', '/^\d+$/', '/^[a-z]{1,2}\d{4,}$/', '/^untitled/', '/^download/', '/^file[-_]?\d*$/', '/^clipboard/', '/^paste/', '/^copy/'] as $p)
            if (preg_match($p, $n))
                return true;
        return mb_strlen($n) < 4;
    }

    /* ════════════════════════════════════════════════════════════
       ADMIN PAGE
    ════════════════════════════════════════════════════════════ */
    public function page()
    {
        $s = $this->cfg();
        $pv = $s['ai_provider'] ?? 'groq';
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
        </head>

        <body>
            <div id="sio-app" class="wrap">
                <div class="sio-header">
                    <div>
                        <h1>🖼 Smart Image Optimizer <span class="vbadge">v4.0 · AI Vision</span></h1>
                        <p>Rename · Resize · Delete unused · Smart categorize — all AI-powered</p>
                    </div>
                    <div id="sio-stats" class="stats-bar"><span class="stat">⏳ Loading...</span></div>
                </div>

                <div class="sio-tabs">
                    <button class="tab active" data-tab="browse">📂 Images</button>
                    <button class="tab" data-tab="bulk">⚡ Bulk AI</button>
                    <button class="tab" data-tab="unused">🗑 Unused</button>
                    <button class="tab" data-tab="cats">📁 Categorize</button>
                    <button class="tab" data-tab="audit">🔍 Audit</button>
                    <button class="tab" data-tab="settings">⚙ Settings</button>
                </div>

                <!-- BROWSE -->
                <div class="pane active" id="tab-browse">
                    <div class="toolbar">
                        <input type="text" id="search" placeholder="🔍 Search..." />
                        <select id="filter">
                            <option value="all">All images</option>
                            <option value="bad">Bad filenames</option>
                            <option value="large">Large (+300KB)</option>
                        </select>
                        <select id="per-page">
                            <option value="24">24/page</option>
                            <option value="48">48/page</option>
                            <option value="96">96/page</option>
                        </select>
                        <button id="load-btn" class="btn primary">Load</button>
                        <label class="chk-lbl"><input type="checkbox" id="sel-all"> Select all visible</label>
                        <span id="sel-count" class="sel-count"></span>
                    </div>
                    <div id="grid" class="grid"></div>
                    <div id="pagination"></div>
                </div>

                <!-- BULK -->
                <div class="pane" id="tab-bulk">
                    <div class="panel">
                        <h2>⚡ Bulk AI Processing</h2>
                        <p class="hint">Select images in the Images tab, choose an operation, then run.</p>

                        <div class="op-row">
                            <label class="op-card active"><input type="radio" name="op" value="rename" checked>
                                <div class="op-ico">🤖</div><strong>Rename only</strong>
                                <span>AI generates descriptive filename from image content</span>
                            </label>
                            <label class="op-card"><input type="radio" name="op" value="resize">
                                <div class="op-ico">📐</div><strong>Resize only</strong>
                                <span>Compress to max dimensions, no renaming</span>
                            </label>
                            <label class="op-card"><input type="radio" name="op" value="both">
                                <div class="op-ico">⚡</div><strong>Rename + Resize</strong>
                                <span>Do both in a single pass</span>
                            </label>
                        </div>

                        <div id="ren-opts">
                            <div class="form-row">
                                <div class="fg"><label>Prefix (optional)</label><input type="text" id="b-prefix"
                                        placeholder="e.g. product, hero" /></div>
                                <div class="fg"><label>Language</label><select id="b-lang">
                                        <option value="en">English (SEO recommended)</option>
                                        <option value="ar">Arabic → English</option>
                                        <option value="auto">Auto-detect</option>
                                    </select></div>
                                <div class="fg"><label>Site context (optional)</label><input type="text" id="b-ctx"
                                        placeholder="e.g. fashion store, restaurant" /></div>
                            </div>
                        </div>
                        <div id="res-opts" style="display:none">
                            <div class="form-row">
                                <div class="fg"><label>Mode</label>
                                    <select id="b-rmode">
                                        <option value="fixed_height">Fixed height — uniform height ✅ recommended</option>
                                        <option value="max_width">Max width (keep ratio)</option>
                                        <option value="crop">Crop to exact size</option>
                                        <option value="resize">Fixed width &amp; height</option>
                                    </select>
                                </div>
                                <div class="fg" id="b-h-wrap"><label>Target height (px)</label><input type="number" id="b-maxh"
                                        value="800" min="50" /></div>
                                <div class="fg" id="b-w-wrap" style="display:none"><label>Max width (px)</label><input
                                        type="number" id="b-maxw" value="1920" min="100" /></div>
                            </div>
                        </div>

                        <div id="bulk-count" class="notice info">No images selected — go to Images tab first</div>
                        <button id="bulk-run" class="btn ai-btn large" disabled>▶ Run</button>

                        <div id="bulk-prog" style="display:none">
                            <div class="prog-wrap">
                                <div class="prog-bar">
                                    <div id="prog-fill"></div>
                                </div><span id="prog-pct">0%</span>
                            </div>
                            <p id="prog-lbl" class="prog-lbl"></p>
                            <div id="bulk-log" class="log"></div>
                            <div id="bulk-sum" class="notice success" style="display:none"></div>
                        </div>
                    </div>
                </div>

                <!-- UNUSED -->
                <div class="pane" id="tab-unused">
                    <div class="panel">
                        <h2>🗑 Unused Images</h2>
                        <p class="hint">Scans for images not referenced in any post, page, widget, or custom field. Review
                            carefully before deleting.</p>
                        <button id="scan-unused" class="btn primary">🔍 Scan for unused images</button>
                        <div id="unused-out" style="margin-top:20px;"></div>
                    </div>
                </div>

                <!-- CATEGORIZE -->
                <div class="pane" id="tab-cats">
                    <div class="panel">
                        <h2>📁 AI Smart Categorization</h2>
                        <p class="hint">The AI analyzes each selected image and groups similar ones into categories. Categories
                            are saved as a WordPress Media taxonomy so you can filter by them in the Media Library.</p>
                        <div class="notice info" style="margin-bottom:16px;">📂 <strong>Real folders (default ON):</strong>
                            Images are physically moved to <code>uploads/categories/{name}/</code> — all links updated
                            automatically.<br>💡 To see these folders in the Media Library sidebar, install the free
                            <strong>FileBird</strong> plugin.</div>
                        <div id="cat-count" class="notice info">No images selected</div>
                        <button id="run-cats" class="btn ai-btn" disabled>🤖 Analyze &amp; Categorize</button>
                        <div id="cat-prog" style="display:none;margin-top:16px;">
                            <div class="prog-wrap">
                                <div class="prog-bar">
                                    <div id="cat-fill"></div>
                                </div><span id="cat-pct">0%</span>
                            </div>
                            <div id="cat-log" class="log" style="margin-top:10px;"></div>
                        </div>
                        <div id="cat-out" style="margin-top:20px;"></div>
                    </div>
                </div>

                <!-- AUDIT -->
                <div class="pane" id="tab-audit">
                    <div class="panel">
                        <h2>🔍 Audit &amp; Fix Broken Links</h2>
                        <p class="hint">Fix broken image references caused by manual renaming outside this plugin.</p>
                        <div class="audit-row">
                            <div class="fg"><label>Old filename</label><input type="text" id="a-old"
                                    placeholder="old-name.jpg" /></div>
                            <div class="fg"><label>New filename</label><input type="text" id="a-new"
                                    placeholder="new-name.jpg" /></div>
                            <div style="display:flex;gap:8px;align-self:flex-end;">
                                <button class="btn secondary" id="a-scan">🔍 Scan</button>
                                <button class="btn success" id="a-fix" disabled>🔧 Fix all</button>
                            </div>
                        </div>
                        <div id="audit-out"></div>
                    </div>
                </div>

                <!-- SETTINGS -->
                <div class="pane" id="tab-settings">
                    <div class="panel">
                        <h2>⚙ AI Provider Settings</h2>
                        <div class="prov-row">
                            <label class="prov-card <?= $pv === 'groq' ? 'active' : '' ?>"><input type="radio" name="prov"
                                    value="groq" <?= checked($pv, 'groq', false) ?>>
                                <span class="badge free">Free ✅</span>
                                <div class="pico">⚡</div><strong>Groq</strong><span>Free globally · No card</span>
                            </label>
                            <label class="prov-card <?= $pv === 'gemini' ? 'active' : '' ?>"><input type="radio" name="prov"
                                    value="gemini" <?= checked($pv, 'gemini', false) ?>>
                                <span class="badge free">Free*</span>
                                <div class="pico">🟢</div><strong>Gemini</strong><span>Free in some regions</span>
                            </label>
                            <label class="prov-card <?= $pv === 'anthropic' ? 'active' : '' ?>"><input type="radio" name="prov"
                                    value="anthropic" <?= checked($pv, 'anthropic', false) ?>>
                                <span class="badge paid">Paid</span>
                                <div class="pico">🟣</div><strong>Claude</strong><span>~$0.001/image · Best accuracy</span>
                            </label>
                        </div>
                        <div class="sfields">
                            <div class="sf p-groq" style="<?= $pv !== 'groq' ? 'display:none' : '' ?>">
                                <label>🔑 Groq API Key <span class="badge free">Free</span></label>
                                <div class="key-row"><input type="password" id="s-groq"
                                        value="<?= esc_attr($s['groq_key'] ?? '') ?>" placeholder="gsk_..." /><button
                                        class="btn secondary tpw">👁</button></div>
                                <small>Get free key → <a href="https://console.groq.com/keys"
                                        target="_blank"><strong>console.groq.com/keys</strong></a> (sign in with Google, no
                                    credit card)</small>
                            </div>
                            <div class="sf p-gemini" style="<?= $pv !== 'gemini' ? 'display:none' : '' ?>">
                                <label>🔑 Google Gemini API Key <span class="badge free">Free</span></label>
                                <div class="key-row"><input type="password" id="s-gemini"
                                        value="<?= esc_attr($s['gemini_key'] ?? '') ?>" placeholder="AIzaSy..." /><button
                                        class="btn secondary tpw">👁</button></div>
                                <small>Get key → <a href="https://aistudio.google.com/app/apikey"
                                        target="_blank"><strong>aistudio.google.com/app/apikey</strong></a></small>
                            </div>
                            <div class="sf p-anthropic" style="<?= $pv !== 'anthropic' ? 'display:none' : '' ?>">
                                <label>🔑 Anthropic API Key</label>
                                <div class="key-row"><input type="password" id="s-claude"
                                        value="<?= esc_attr($s['api_key'] ?? '') ?>" placeholder="sk-ant-api03-..." /><button
                                        class="btn secondary tpw">👁</button></div>
                                <small>Get key → <a href="https://console.anthropic.com/settings/keys"
                                        target="_blank">console.anthropic.com</a> (requires billing)</small>
                                <div class="fg" style="margin-top:10px;max-width:280px;"><label>Model</label>
                                    <select id="s-model">
                                        <option value="claude-haiku-4-5-20251001"
                                            <?= selected($s['ai_model'], 'claude-haiku-4-5-20251001', false) ?>>Claude Haiku (fast
                                            ✅)</option>
                                        <option value="claude-sonnet-4-6" <?= selected($s['ai_model'], 'claude-sonnet-4-6', false) ?>>Claude Sonnet (accurate)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row" style="margin-top:16px;">
                                <div class="fg"><label>🌍 Filename language</label><select id="s-lang">
                                        <option value="en" <?= selected($s['ai_language'], 'en', false) ?>>English (recommended)
                                        </option>
                                        <option value="ar" <?= selected($s['ai_language'], 'ar', false) ?>>Arabic → English
                                        </option>
                                        <option value="auto" <?= selected($s['ai_language'], 'auto', false) ?>>Auto-detect</option>
                                    </select></div>
                                <div class="fg"><label>📌 Site context (improves accuracy)</label><input type="text" id="s-ctx"
                                        value="<?= esc_attr($s['ai_context'] ?? '') ?>"
                                        placeholder="e.g. online fashion store, restaurant menu" /></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;margin-top:20px;">
                            <button class="btn ai-btn" id="save-set">💾 Save Settings</button>
                            <button class="btn secondary" id="test-con">🔬 Test Connection</button>
                            <span id="set-msg" style="font-size:13px;"></span>
                        </div>
                        <div class="info-grid" style="margin-top:28px;">
                            <div class="ic green">
                                <h3>⚡ Groq — Free &amp; Global</h3>
                                <p>Completely free, no credit card, works worldwide. Powered by Llama 4 Vision. <a
                                        href="https://console.groq.com/keys" target="_blank">console.groq.com/keys</a></p>
                            </div>
                            <div class="ic">
                                <h3>💰 Claude cost estimate</h3>
                                <p>1000 images ≈ <strong>$0.50–$1.00</strong> with Claude Haiku depending on file size.</p>
                            </div>
                            <div class="ic warn">
                                <h3>⚠ Before running</h3>
                                <p>Backup your database first. Test on 5 images before bulk runs. Rename &amp; delete operations
                                    cannot be undone.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODAL -->
                <div id="modal" class="modal" style="display:none">
                    <div class="mbox"><button class="mclose">✕</button>
                        <div id="mbody"></div>
                    </div>
                </div>
            </div>
        </body>

        </html>
    <?php }
}
new SmartImageOptimizer();
