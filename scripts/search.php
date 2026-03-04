<?php
header('Content-Type: application/json');

// check keyword
$keyword = trim($_GET['keyword'] ?? '');

if ($keyword === '') {
    echo '{}';
    exit();
}

// search data
$language = $_GET['language'] ?? 'default';

$search_json = file_get_contents(__DIR__ . '/../data/search.json');
$search_data = json_decode($search_json);
$search_data = $search_data->$language ?? (object) [];

// add collection pages
foreach ($search_data as $index => $page) {
    if (!$page->is_template) {
        continue;
    }

    $items = Scripts\Collection::getItems(
        collection_id: $page->collection_id,
        sort: true,
    );

    foreach ($items as $item_index => $item) {
        if (!empty($item['unsearchable'])) {
            continue;
        }

        $item_url = getValue($item['url'] ?? null, $language);
        $item_href = getValue($item['href'] ?? null, $language);

        if ($item_href != 'collection-item' && $item_href != $item_url) {
            continue;
        }

        $item_page = clone $page;
        $item_page->url = $item_url;
        $item_page->title = getValue($item['name'] ?? null, $language);
        $item_page->title = htmlToPlain($item_page->title);

        $item_page->content_php = array_map(
            fn($content_php) => preg_replace(
                '/(Collection::getItem\w*\(.*?)(\);)/s',
                "$1, null, {$item_index}$2",
                $content_php,
            ),
            $item_page->content_php,
        );

        $search_data[] = $item_page;
    }

    unset($search_data[$index]);
}

// add pagination pages
foreach ($search_data as $page) {
    if (!$page->collection_options) {
        continue;
    }

    $collection_options = json_decode($page->collection_options);
    $items_per_page = $collection_options->items_per_page ?? 0;

    if (!$items_per_page) {
        continue;
    }

    $limit = $collection_options->limit ?? 0;
    $item_count =
        $limit ?: count(Scripts\Collection::getItems($page->collection_id));
    $page_count = ceil($item_count / $items_per_page);

    for ($page_number = 2; $page_number <= $page_count; $page_number++) {
        $pagination_page = clone $page;

        $pagination_page->pagination_url = Scripts\Collection::getPaginationUrl(
            $page_number,
            $page->url,
        );

        $pagination_page->content_php = array_map(
            fn($content_php) => preg_replace(
                '/(Collection::getHtml\(.*?)(\);)/s',
                "$1, {$page_number}$2",
                $content_php,
            ),
            $pagination_page->content_php,
        );

        $search_data[] = $pagination_page;
    }
}

$content_pattern = <<<REGEX
/
    <([a-z0-9\-]+)\s(?:[^>]*[\s"])?class\s*=\s*"(?:[^"]*\s)?(?:heading|text|handler)-\d+["\s][^>]*>
    (.*?)
    <\/\\1>
/isx
REGEX;

$keyword_pattern = preg_replace(
    ['/[^\w\-\s]*/', '/^/', '/\s+/', '/$/'],
    ['', '/(', ')(.+)(', ')/i'],
    $keyword,
);

$result_urls = [];
$results = [];
$result_count = 0;
$result_content_max_length = 100;

foreach ($search_data as $page) {
    if (in_array($page->url, $result_urls)) {
        continue;
    }

    $keyword_match = false;

    // set php content
    $page_php_content = implode(
        "\n",
        array_map(function ($php) {
            $php_content = '';

            try {
                eval("\$php_content .= {$php}");
            } catch (Throwable $t) {
                return '';
            }

            if (!preg_match('/^\s*<[a-z0-9\-]+[\s>]/i', $php_content)) {
                $php_content = <<<HTML
                <div class="text-1">{$php_content}</div>
                HTML;
            }

            return $php_content;
        }, $page->content_php),
    );

    if (preg_match_all($content_pattern, $page_php_content, $content_matches)) {
        $page_php_content = implode('<div>', $content_matches[2]);
        $page_php_content = htmlToPlain($page_php_content);
    } else {
        $page_php_content = '';
    }

    $page_content = implode(
        "\n",
        array_filter([$page_php_content, $page->content]),
    );

    // check title
    if (preg_match($keyword_pattern, $page->title)) {
        $keyword_match = true;
    }

    $page_snippet = '';
    $page_sentences = explode("\n", $page_content);

    // check content
    if (preg_match($keyword_pattern, $page_content)) {
        $keyword_match = true;
        $keyword_sentence_index = 0;

        foreach ($page_sentences as $page_sentence_index => $page_sentence) {
            if (preg_match($keyword_pattern, $page_sentence)) {
                $keyword_sentence_index = $page_sentence_index;
                break;
            }
        }

        switch ($keyword_sentence_index) {
            case 0:
                $page_snippet = $page_sentences[0] ?? '';

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet .= ' ';
                    $page_snippet .= $page_sentences[1] ?? '';
                }

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet .= ' ';
                    $page_snippet .= $page_sentences[2] ?? '';
                }
                break;
            case 1:
                $page_snippet = $page_sentences[1];

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet = $page_sentences[0] . ' ' . $page_snippet;
                }

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet .= ' ';
                    $page_snippet .= $page_sentences[2] ?? '';
                }
                break;
            default:
                $page_snippet .= $page_sentences[$keyword_sentence_index];

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet =
                        $page_sentences[$keyword_sentence_index - 1] .
                        ' ' .
                        $page_snippet;
                }

                if (strlen($page_snippet) < $result_content_max_length) {
                    $page_snippet =
                        $page_sentences[$keyword_sentence_index - 2] .
                        ' ' .
                        $page_snippet;
                }
        }
    } elseif ($keyword_match && $page_sentences) {
        $page_snippet = $page_sentences[0];

        if (strlen($page_snippet) < $result_content_max_length) {
            $page_snippet .= ' ';
            $page_snippet .= $page_sentences[1] ?? '';
        }

        if (strlen($page_snippet) < $result_content_max_length) {
            $page_snippet .= ' ';
            $page_snippet .= $page_sentences[2] ?? '';
        }
    }

    if (!$keyword_match) {
        continue;
    }

    $result_urls[] = $page->url;

    $results[] = [
        'url' => $page->pagination_url ?? $page->url,
        'title' => preg_replace_callback(
            $keyword_pattern,
            'highlightMatches',
            $page->title,
        ),
        'content' => preg_replace_callback(
            $keyword_pattern,
            'highlightMatches',
            ltrim(rtrim($page_snippet), ' .'),
        ),
    ];

    $result_count++;

    if ($result_count == 10) {
        break;
    }
}

function highlightMatches(array $matches): string
{
    $output = '';

    foreach ($matches as $index => $match) {
        if ($index) {
            $output .= $index % 2 == 0 ? $match : "<strong>{$match}</strong>";
        }
    }

    return $output;
}

function getValue($subject, ?string $language = null): ?string
{
    if (is_array($subject)) {
        $subject = array_key_exists($language, $subject)
            ? $subject[$language]
            : array_values($subject)[0];
    }

    return $subject;
}

function htmlToPlain(?string $subject): string
{
    $subject = preg_replace(
        [
            '/(?:\s|&nbsp;|&#160;)+/',
            '/<(?:div|h\d+|p|li|dt|dd|br)(?:\s[^>]*)?>/i',
            '/<\/?[a-z0-9\-]+(?:\s[^>]*)?>/i',
            '/([.!?]) *([A-Z](?!\.))/',
            '/ +$/m',
            '/ {2,}/',
            '/\n{2,}/',
        ],
        [' ', "\n", ' ', "$1\n$2", '', ' ', "\n"],
        $subject,
    );

    return html_entity_decode($subject);
}

if ($results) {
    echo json_encode($results);
    exit();
}

// no results
$form_id = (int) ($_GET['id'] ?? 0);
$language = $_GET['language'] ?? null;

$forms_json = file_get_contents(__DIR__ . '/../data/forms.json');
$forms_data = json_decode($forms_json);
$form_data = $forms_data->forms->$form_id ?? null;

if (
    $language &&
    isset($form_data->feedback->languages->$language->no_results)
) {
    $results[]['content'] =
        $form_data->feedback->languages->$language->no_results;
} else {
    $results[]['content'] = $form_data->feedback->no_results ?? null;
}

echo json_encode($results);
?>
