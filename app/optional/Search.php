<?php
declare(strict_types=1);

namespace app\optional;

if (!defined('APP_ROOT')) exit;

final class Search
{
    private static function pagination(bool $has_prev, bool $has_next, int $page, string $query, string $field): string
    {
        if ($page >= max_pagination_pages()) $has_next = false;
        if (!$has_prev && !$has_next) return '';
        $button = static function (int $target, string $label) use ($query, $field): string {
            return '<form method="post" action="' . h(route_url('search')) . '" data-no-ajax="1">' . form_token() . hidden_inputs(['q' => $query, 'field' => $field, 'p' => $target]) . '<button type="submit">' . $label . '</button></form>';
        };
        return '<div class="pagination-bar search-page-pagination"><div class="pagination"><ul>'
            . ($has_prev ? '<li>' . $button(max(1, $page - 1), '上一页') . '</li>' : '')
            . '<li class="active"><span>' . $page . '</span></li>'
            . ($has_next ? '<li>' . $button($page + 1, '下一页') . '</li>' : '')
            . '</ul></div></div>';
    }

    public static function page(): void
    {
        if (!uid()) err('请登录后操作');
        $submitted = is_post_request();
        $query = $submitted ? post('q', length_limit('search', 'max')) : '';
        $field = topic_search_field($submitted ? (string)($_POST['field'] ?? 'title') : 'title');
        $page = $submitted ? min(max_pagination_pages(), max(1, (int)($_POST['p'] ?? 1))) : 1;
        if ($query !== '') require_search_min_chars($query);
        $options = ['title' => '标题', 'body' => '内容', 'reply' => '回帖'];
        $radios = '';
        foreach ($options as $value => $label) $radios .= '<label class="search-page-radio"><input type="radio" name="field" value="' . $value . '"' . ($field === $value ? ' checked' : '') . '><span>' . $label . '</span></label>';
        $form = '<form class="search-page-form" method="post" action="' . h(route_url('search')) . '" data-no-ajax="1">' . form_token() . '<div class="search-page-query"><input type="search" name="q" value="' . h($query) . '" placeholder="搜索关键词"' . length_attributes('q') . ' required autofocus><button type="submit">搜索</button></div><div class="search-page-types" role="radiogroup" aria-label="搜索范围">' . $radios . '</div></form>';
        $main = '<div class="search-page-head"><h2>搜索</h2>' . $form . '</div>';
        if ($submitted && $query === '') $main .= '<div class="empty-state">请输入搜索关键词</div>';
        if ($query !== '') {
            if ($page === 1) {
                $seconds = post_interval_seconds();
                if ($seconds > 0) {
                    $user = row('app_users', 'id', uid());
                    $wait = $seconds - (time() - (int)($user['last_post_at'] ?? 0));
                    if ($wait > 0) err('搜索太频繁，请 ' . $wait . ' 秒后再试');
                    q('UPDATE app_users SET last_post_at=? WHERE id=?', [time(), uid()]);
                }
            }
            $size = max(1, (int)setting('topics_per_page', '30'));
            $data = topic_index_data(0, null, 'topics', $query, $field, 'comment', $page, $size);
            $main .= '<div class="search-page-summary">搜索“' . h($query) . '” · ' . $options[$field] . '</div><ul class="post-list search-page-results">';
            if (!$data['rows']) $main .= '<li class="empty-state">没有找到匹配的' . ($field === 'reply' ? '回帖' : '主题') . '</li>';
            else foreach ($data['rows'] as $row) {
                $row['time'] = (int)($row['list_time'] ?? $row['my_reply_at'] ?? ($row['last_reply_at'] ?: $row['created_at']));
                $row['forum'] = forum_by_id((int)$row['forum_id']) ?: ['id' => 0, 'name' => ''];
                $main .= topic_list_row($row, 'comment');
            }
            $main .= '</ul>' . self::pagination($page > 1, (bool)$data['has_next_page'], $page, $query, $field);
        }
        page('搜索', shell_html($main, '', 'search-page'));
    }
}
