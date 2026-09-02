<?php
/**
 * includes/linter.php — ruleset di authoring condiviso (documento di lavoro §11.4).
 *
 * Nessuna dipendenza dall'ambiente admin: funzioni pure, riusabili da una CLI o
 * da una GitHub Action. Usa parse_blocks() se disponibile.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blocchi core ammessi senza dichiarazione esplicita in blockTypes.
 *
 * @return string[]
 */
function lfw_patterns_core_whitelist() {
    return array(
        'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph',
        'core/list', 'core/list-item', 'core/buttons', 'core/button', 'core/image',
        'core/cover', 'core/media-text', 'core/spacer', 'core/separator', 'core/quote',
        'core/pullquote', 'core/details', 'core/table', 'core/embed', 'core/video',
        'core/audio', 'core/file', 'core/gallery', 'core/social-links', 'core/social-link',
        'core/navigation', 'core/navigation-link', 'core/navigation-submenu',
        'core/site-title', 'core/site-logo', 'core/site-tagline', 'core/search',
        'core/loginout', 'core/home-link', 'core/read-more', 'core/query',
        'core/post-template', 'core/post-title', 'core/post-excerpt',
        'core/post-featured-image', 'core/post-date', 'core/post-terms', 'core/post-author',
        'core/query-pagination', 'core/query-pagination-previous',
        'core/query-pagination-next', 'core/query-pagination-numbers',
        'core/query-no-results', 'core/query-title', 'core/code', 'core/preformatted',
        'core/verse', 'core/more', 'core/nextpage', 'core/footnotes', 'core/avatar',
        'core/post-content', 'core/template-part', 'core/shortcode',
    );
}

/**
 * Analizza un pattern secondo lo standard §6.
 *
 * @param string $content Markup dei blocchi.
 * @param array  $meta    title, slug, categories, keywords, viewportWidth, description.
 * @return array{errors:array,warnings:array,fixes:array,blocks:array} Ogni voce di
 *               errors/warnings: array{code:string,msg:string,items:array}.
 *               Ogni fix: array{id:string,label:string}.
 */
function lfw_patterns_lint($content, $meta = array()) {
    $errors = array();
    $warnings = array();
    $fixes = array();

    $snip = function ($matches) {
        $out = array();
        foreach (array_slice(array_values(array_unique((array) $matches)), 0, 5) as $s) {
            $s = (string) $s;
            if (strlen($s) > 160) {
                $s = substr($s, 0, 160) . '…';
            }
            $out[] = $s;
        }
        return $out;
    };
    $add_e = function ($code, $msg, $items = array(), $snips = array()) use (&$errors, $snip) {
        $errors[] = array('code' => $code, 'msg' => $msg, 'items' => array_values($items), 'snippets' => $snip($snips));
    };
    $add_w = function ($code, $msg, $items = array(), $snips = array()) use (&$warnings, $snip) {
        $warnings[] = array('code' => $code, 'msg' => $msg, 'items' => array_values($items), 'snippets' => $snip($snips));
    };
    $add_f = function ($id, $label) use (&$fixes) {
        foreach ($fixes as $f) {
            if ($f['id'] === $id) {
                return;
            }
        }
        $fixes[] = array('id' => $id, 'label' => $label);
    };

    $content = (string) $content;

    /* ---- schema -------------------------------------------------------- */
    if (empty($meta['title'])) {
        $add_e('schema_title', __('Manca il titolo.', 'lfw-cloud-patterns'));
    }
    if (empty($meta['slug'])) {
        $add_e('schema_slug', __('Manca lo slug.', 'lfw-cloud-patterns'));
    } elseif (!preg_match('#^([a-z0-9-]+/)?[a-z0-9-]+$#', (string) $meta['slug'])) {
        $add_w('schema_slug_format', __('Slug non in kebab-case (o namespace/kebab).', 'lfw-cloud-patterns'), array($meta['slug']));
    }
    if ('' === trim($content) || false === strpos($content, '<!-- wp:')) {
        $add_e('schema_content', __('Contenuto vuoto o senza blocchi Gutenberg.', 'lfw-cloud-patterns'));
    }

    /* ---- bilanciamento delimitatori --------------------------------- */
    // La parte JSON è "temperata" (?!-->) così un match non può scavalcare un
    // delimitatore e inglobare il blocco successivo. I blocchi void ".../-->"
    // non vengono contati (non hanno chiusura): niente falso "unbalanced".
    $real_open = preg_match_all('/<!--\s+wp:[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?(?:\s+\{(?:(?!-->).)*?\})?\s+-->/s', $content);
    $close = preg_match_all('/<!--\s+\/wp:[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?\s+-->/', $content);
    if ($real_open !== $close) {
        $add_e('unbalanced', sprintf(
            /* translators: 1: open count, 2: close count */
            __('Delimitatori sbilanciati: %1$d aperti, %2$d chiusi.', 'lfw-cloud-patterns'),
            (int) $real_open,
            (int) $close
        ));
    }

    /* ---- inventario blocchi ----------------------------------------- */
    $names = array();
    $anomaly = false;
    if (function_exists('parse_blocks')) {
        $stack = parse_blocks($content);
        while ($stack) {
            $b = array_pop($stack);
            if (!empty($b['blockName'])) {
                $names[$b['blockName']] = true;
            } elseif (array_key_exists('blockName', $b) && null === $b['blockName']
                && '' !== trim((string) ($b['innerHTML'] ?? ''))
                && false !== strpos((string) $b['innerHTML'], 'wp:')) {
                $anomaly = true;
            }
            if (!empty($b['innerBlocks'])) {
                foreach ($b['innerBlocks'] as $ib) {
                    $stack[] = $ib;
                }
            }
        }
    } elseif (preg_match_all('/<!--\s+wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)/', $content, $bm)) {
        // Fallback CLI/CI (nessun WordPress): nomi blocco dai delimitatori di apertura.
        foreach ($bm[1] as $bn) {
            $names[(false === strpos($bn, '/')) ? 'core/' . $bn : $bn] = true;
        }
    }
    $names = array_keys($names);
    sort($names);

    $whitelist = lfw_patterns_core_whitelist();
    $noncore = array();
    $has_html = false;
    foreach ($names as $n) {
        if ('core/html' === $n || 'core/freeform' === $n) {
            $has_html = true;
            continue;
        }
        if (!in_array($n, $whitelist, true)) {
            $noncore[] = $n;
        }
    }
    if ($anomaly) {
        $add_e('parse_anomaly', __('Markup non interpretabile come blocchi (delimitatore rotto?).', 'lfw-cloud-patterns'));
    }
    if ($noncore) {
        $alt = lfw_patterns_noncore_alternatives();
        $items = array();
        foreach (array_unique($noncore) as $nc) {
            $items[] = isset($alt[$nc]) ? ($nc . '  →  ' . $alt[$nc]) : $nc;
        }
        $add_e('noncore_block', __('Blocchi non core / non portabili (si rompono dove il tema/plugin manca):', 'lfw-cloud-patterns'), $items);
    }
    if ($has_html) {
        $add_w('core_html', __('Contiene core/html: verifica che dentro non ci siano script o risorse esterne.', 'lfw-cloud-patterns'));
    }

    /* ---- elementi legati a questo specifico sito ------------------ */
    $np = array();
    if (in_array('core/template-part', $names, true)) {
        $np[] = 'core/template-part';
    }
    if (preg_match('/"ref":\d+/', $content)) {
        $np[] = '"ref":N (blocco riutilizzabile o menu del sito)';
        // Se ogni "ref" è dentro un core/navigation, offri il fix mirato:
        // togliere il ref fa sì che l'header/footer adotti il menu del sito di destinazione.
        $refs_total = preg_match_all('/"ref":\d+/', $content);
        $refs_nav   = preg_match_all('/<!--\s+wp:navigation\s+\{(?:(?!-->).)*?"ref":\d+(?:(?!-->).)*?\}\s+\/?-->/s', $content);
        if ($refs_total > 0 && $refs_total === $refs_nav) {
            $add_f('strip_nav_refs', __('Rimuovi "ref" da core/navigation → usa il menu del sito di destinazione', 'lfw-cloud-patterns'));
        }
    }
    if ($np) {
        $add_e('nonportable_block', __('Elementi che non sopravvivono al trasferimento su un altro sito (rimuovili o sostituiscili con blocchi statici):', 'lfw-cloud-patterns'), $np);
    }
    if (in_array('core/shortcode', $names, true)) {
        $add_w('shortcode', __('core/shortcode: lo shortcode potrebbe non esistere sul sito di destinazione.', 'lfw-cloud-patterns'));
    }

    /* ---- colori grezzi -------------------------------------------- */
    if (preg_match_all('/"color":\{[^{}]*?"text":"(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))"/', $content, $mt)) {
        $add_e('raw_text_color', __('Colore grezzo sul testo (usa uno slug di preset o eredita). Fix: "Rimuovi i colori del testo", oppure mappa i colori qui sotto.', 'lfw-cloud-patterns'), array_unique($mt[1]), $mt[0]);
    }
    if (preg_match_all('/"(?:background|gradient)":"(#[0-9a-fA-F]{3,8}|linear-gradient\([^"]*|radial-gradient\([^"]*|rgba?\([^)]*\)|hsla?\([^)]*\))"?/', $content, $mb)) {
        $add_w('raw_deco_color', __('Colore/gradiente grezzo su sfondo (ammesso solo se decorativo, §6.7). Mappa i colori qui sotto se non è decorativo.', 'lfw-cloud-patterns'), array_unique($mb[1]), $mb[0]);
    }

    /* ---- px (valori con "px", numeri nudi, e CSS inline) --------- */
    $fs_hits = array();
    if (preg_match_all('/"fontSize":"(\d+(?:\.\d+)?px)"/', $content, $m)) { $fs_hits = array_merge($fs_hits, $m[0]); }
    if (preg_match_all('/"fontSize":(\d+(?:\.\d+)?)(?=[,}])/', $content, $m)) { $fs_hits = array_merge($fs_hits, $m[0]); }
    if (preg_match_all('/font-size:(\d+(?:\.\d+)?)px/', $content, $m)) { $fs_hits = array_merge($fs_hits, $m[0]); }
    if ($fs_hits) {
        $add_w('px_fontsize', __('font-size in px (o numero nudo): non scala con il tema. Fix: "px → slug di scala".', 'lfw-cloud-patterns'), array(), array_slice(array_unique($fs_hits), 0, 5));
        $add_f('px_fontsize_to_scale', __('font-size px → slug di scala del tema (small/medium/large/…)', 'lfw-cloud-patterns'));
    }

    $sp_hits = array();
    if (preg_match_all('/"(?:top|right|bottom|left|blockGap|padding|margin)":"(\d+(?:\.\d+)?px)"/', $content, $m)) { $sp_hits = array_merge($sp_hits, $m[0]); }
    if (preg_match_all('/\b(?:padding|margin|gap|row-gap|column-gap)(?:-(?:top|right|bottom|left))?:(\d+(?:\.\d+)?)px/', $content, $m)) { $sp_hits = array_merge($sp_hits, $m[0]); }
    if ($sp_hits) {
        $add_w('px_spacing', __('spaziatura in px: non segue la scala del tema. Fix: "px → rem" (o usa var:preset|spacing|NN a mano).', 'lfw-cloud-patterns'), array(), array_slice(array_unique($sp_hits), 0, 5));
        $add_f('px_to_rem', __('spaziatura/altezza px → rem (relativo, non rompe il layout)', 'lfw-cloud-patterns'));
    }

    $h_hits = array();
    if (preg_match_all('/"(?:height|minHeight|width|minWidth)":"(\d+(?:\.\d+)?px)"/', $content, $m)) { $h_hits = array_merge($h_hits, $m[0]); }
    if (preg_match_all('/"(?:height|width)":(\d+(?:\.\d+)?)(?=[,}])/', $content, $m)) { $h_hits = array_merge($h_hits, $m[0]); }
    if (preg_match_all('/\b(?:min-)?(?:height|width):(\d+(?:\.\d+)?)px/', $content, $m)) { $h_hits = array_merge($h_hits, $m[0]); }
    if ($h_hits) {
        $add_w('px_height', __('altezza/larghezza fissa in px (o numero nudo, es. core/spacer): usa aspectRatio, vh/svh, o rem. Fix: "px → rem".', 'lfw-cloud-patterns'), array(), array_slice(array_unique($h_hits), 0, 5));
        $add_f('px_to_rem', __('spaziatura/altezza px → rem (relativo, non rompe il layout)', 'lfw-cloud-patterns'));
    }

    /* ---- asset esterni ------------------------------------------ */
    if (preg_match_all('/(?:src|poster|data-src)="(https?:\/\/[^"]+)"/', $content, $ms)) {
        $add_e('external_asset', __('Risorsa esterna (link che si rompe, privacy/CSP). Fix: "Immagini esterne → segnaposto visivo", oppure rimuovi il blocco.', 'lfw-cloud-patterns'), array_slice(array_unique($ms[1]), 0, 20), array_slice(array_unique($ms[0]), 0, 5));
    }
    if (preg_match('/url\(\s*["\']?https?:\/\//', $content)) {
        $add_w('external_url_css', __('URL http dentro uno style inline.', 'lfw-cloud-patterns'));
    }

    /* ---- link assoluti / http ---------------------------------- */
    if (preg_match_all('/\bhref="(https?:\/\/[^"]+)"/', $content, $ml)) {
        $add_w('absolute_link', __('Link assoluto in href: se punta al sito d\'origine si rompe altrove. Fix: "Link → #".', 'lfw-cloud-patterns'), array_slice(array_unique($ml[1]), 0, 12), array_slice(array_unique($ml[0]), 0, 5));
        $add_f('links_to_hash', __('Sostituisci gli href assoluti con "#"', 'lfw-cloud-patterns'));
    }
    if (preg_match_all('/(?:href|src|poster|content)="(http:\/\/[^"]+)"/', $content, $mi2)) {
        $add_w('insecure_url', __('URL http:// non sicuro (mixed content sui siti in https). Fix: "http → https".', 'lfw-cloud-patterns'), array_slice(array_unique($mi2[1]), 0, 10), array_slice(array_unique($mi2[0]), 0, 5));
        $add_f('http_to_https', __('http:// → https:// (href/src/url)', 'lfw-cloud-patterns'));
    }

    /* ---- anchor/id fissi sul blocco --------------------------- */
    if (preg_match_all('/"anchor":"([^"]+)"/', $content, $man)) {
        $add_w('block_anchor', __('anchor/id fisso sul blocco: inserendo il pattern due volte nella stessa pagina l\'id risulta duplicato. Fix: "Rimuovi gli anchor".', 'lfw-cloud-patterns'), array_unique($man[1]), $man[0]);
        $add_f('strip_anchors', __('Rimuovi gli anchor (attributo "anchor" + relativo id="")', 'lfw-cloud-patterns'));
    }

    /* ---- slug colore specifici del tema ------------------------ */
    // Solo slug davvero fragili (accent-N, foreground, tertiary di TT4/TT5).
    // base/contrast/primary/secondary sono la baseline consigliata (§6.2-A) e non si segnalano.
    // `has-background` da solo è una classe core, non uno slug: escluso.
    if (preg_match_all('/preset\|color\|(accent-[1-6]|foreground|tertiary)\b|has-(accent-[1-6]|foreground|tertiary)-(?:background-)?color/', $content, $mc)) {
        $slugs = array_filter(array_unique(array_merge($mc[1], $mc[2])));
        $add_w('theme_color_slug', __('Slug colore specifici di TT4/TT5 (su un altro tema possono "spegnersi" — valuta i token lfw-*, §6.2-B):', 'lfw-cloud-patterns'), $slugs, array_slice(array_unique($mc[0]), 0, 5));
        $add_f('strip_theme_colors', __('Rimuovi gli slug colore accent-*/foreground/tertiary → eredita dal tema', 'lfw-cloud-patterns'));
    }

    /* ---- classi/attributi utility di Twentig (framework "tw-") --- */
    // tw-cols-stack-*, tw-stack, ecc. + attributi JSON twStack/twGap/…: inerti
    // senza il plugin Twentig (impilamento colonne responsive, spaziature).
    $tw_hits = array();
    if (preg_match_all('/\btw-[a-z][a-z0-9-]*\b/', $content, $m)) { $tw_hits = array_merge($tw_hits, $m[0]); }
    if (preg_match_all('/"tw[A-Z][A-Za-z0-9]*":/', $content, $m)) { $tw_hits = array_merge($tw_hits, $m[0]); }
    if ($tw_hits) {
        $add_w('vendor_class', __('Classi/attributi utility di Twentig ("tw-…", "twStack"…): senza quel plugin sono inerti (impilamento colonne responsive, spaziature).', 'lfw-cloud-patterns'), array_slice(array_unique($tw_hits), 0, 8));
        $add_f('strip_vendor_classes', __('Rimuovi le classi/attributi Twentig (tw-*, twStack…)', 'lfw-cloud-patterns'));
    }

    /* ---- fontFamily ------------------------------------------- */
    if (preg_match('/"fontFamily":"|has-[a-z0-9-]+-font-family/', $content)) {
        $add_w('fontfamily', __('fontFamily impostato: meglio ereditare dal tema.', 'lfw-cloud-patterns'));
        $add_f('strip_fontfamily', __('Rimuovi fontFamily', 'lfw-cloud-patterns'));
    }

    /* ---- patternName esterno --------------------------------- */
    if (preg_match_all('/"patternName":"([^"]+)"/', $content, $mpn)) {
        $add_w('patternname', __('metadata.patternName residuo di un altro tema:', 'lfw-cloud-patterns'), array_unique($mpn[1]), $mpn[0]);
        $add_f('strip_patternname', __('Rimuovi metadata.patternName', 'lfw-cloud-patterns'));
    }

    /* ---- classi di animazione / plugin ---------------------- */
    if (preg_match('/(?:^|[\s"])agl(?:-[a-z0-9]+)?(?:[\s"]|$)/', $content)) {
        $add_w('anim_classes', __('Classi di animazione Twentig ("agl…"): inerti senza quel plugin.', 'lfw-cloud-patterns'));
        $add_f('strip_anim_classes', __('Rimuovi le classi agl*', 'lfw-cloud-patterns'));
    }

    /* ---- contentSize fisso --------------------------------- */
    if (preg_match('/"contentSize":"[^"]+"/', $content)) {
        $add_w('fixed_contentsize', __('contentSize fisso nel pattern: meglio lasciarlo al tema.', 'lfw-cloud-patterns'));
    }

    /* ---- immagini esterne: fix disponibile ---------------- */
    if (preg_match('/<img[^>]+\bsrc="https?:\/\//', $content)
        || preg_match('/background-image:\s*url\(\s*["\']?https?:\/\//', $content)
        || preg_match('/"url":"https?:\/\/[^"]+\.(?:png|jpe?g|gif|webp|avif|svg)/i', $content)) {
        $add_f('img_to_placeholder', __('Immagini esterne → segnaposto visivo (SVG inline, tiene proporzioni/alt; resta un core/image sostituibile con un clic)', 'lfw-cloud-patterns'));
    }

    /* ---- ID allegato: non portabili --------------------- */
    $has_img_ctx = (bool) preg_match('/<!--\s+wp:(?:image|cover|media-text|gallery|video|audio|file)\b/', $content);
    $has_wp_image = (bool) preg_match_all('/\bwp-image-\d+\b/', $content, $mi);
    if ($has_wp_image) {
        $add_w('attachment_id', __('ID allegato nel markup (wp-image-N): valido solo sul sito di origine, altrove è un riferimento morto.', 'lfw-cloud-patterns'), array_unique($mi[0]), $mi[0]);
    }
    if ($has_wp_image || ($has_img_ctx && preg_match('/"id":\d+/', $content))) {
        $add_f('strip_image_ids', __('Rimuovi ID allegato (wp-image-N, "id":N)', 'lfw-cloud-patterns'));
    }

    /* ---- colori del testo: fix opt-in "eredita dal tema" --- */
    if (preg_match('/"textColor":"|"color":\{[^{}]*"text":"|has-text-color/', $content)) {
        $add_f('strip_text_colors', __('Rimuovi i colori del testo → eredita dal tema (§6.2-A)', 'lfw-cloud-patterns'));
    }

    return array(
        'errors'   => $errors,
        'warnings' => $warnings,
        'fixes'    => $fixes,
        'blocks'   => $names,
    );
}

/**
 * Riepilogo a una riga dell'esito lint.
 *
 * @param array $report Output di lfw_patterns_lint().
 * @return string
 */
function lfw_patterns_lint_summary($report) {
    $e = count($report['errors']);
    $w = count($report['warnings']);
    if (0 === $e && 0 === $w) {
        return __('OK — nessun problema.', 'lfw-cloud-patterns');
    }
    return sprintf(
        /* translators: 1: error count, 2: warning count */
        _n('%1$d errore', '%1$d errori', $e, 'lfw-cloud-patterns') . ', ' .
        _n('%2$d avviso', '%2$d avvisi', $w, 'lfw-cloud-patterns'),
        $e,
        $w
    );
}

/* =========================================================================
 * Auto-fix — solo trasformazioni meccaniche sicure. Ogni fix agisce sia
 * sugli attributi JSON del commento sia sull'HTML renderizzato quando serve.
 * Ritorna il contenuto modificato; l'elenco dei fix applicati è in $applied.
 * ====================================================================== */

/**
 * @param string   $content  Markup dei blocchi.
 * @param string[] $fix_ids  ID dei fix da applicare (da lfw_patterns_lint()['fixes']).
 * @param array    $applied  (out) elenco degli ID effettivamente applicati.
 * @return string
 */
function lfw_patterns_autofix($content, $fix_ids, &$applied = null) {
    $applied = array();
    $content = (string) $content;
    $fix_ids = (array) $fix_ids;

    if (in_array('strip_patternname', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_patternname($content);
        if ($new !== $content) {
            $applied[] = 'strip_patternname';
            $content = $new;
        }
    }
    if (in_array('strip_anim_classes', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_class_tokens($content, '/\bagl(?:-[a-z0-9]+)*\b/');
        if ($new !== $content) {
            $applied[] = 'strip_anim_classes';
            $content = $new;
        }
    }
    if (in_array('strip_vendor_classes', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_vendor_classes($content);
        if ($new !== $content) {
            $applied[] = 'strip_vendor_classes';
            $content = $new;
        }
    }
    if (in_array('strip_theme_colors', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_theme_colors($content);
        if ($new !== $content) {
            $applied[] = 'strip_theme_colors';
            $content = $new;
        }
    }
    if (in_array('strip_nav_refs', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_nav_refs($content);
        if ($new !== $content) {
            $applied[] = 'strip_nav_refs';
            $content = $new;
        }
    }
    if (in_array('strip_fontfamily', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_fontfamily($content);
        if ($new !== $content) {
            $applied[] = 'strip_fontfamily';
            $content = $new;
        }
    }
    if (in_array('img_to_placeholder', $fix_ids, true)) {
        $new = lfw_patterns_fix_img_placeholder($content);
        if ($new !== $content) {
            $applied[] = 'img_to_placeholder';
            $content = $new;
        }
    }
    if (in_array('strip_image_ids', $fix_ids, true)) {
        $new = lfw_patterns_strip_attachment_ids($content);
        if ($new !== $content) {
            $applied[] = 'strip_image_ids';
            $content = $new;
        }
    }
    if (in_array('strip_text_colors', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_text_colors($content);
        if ($new !== $content) {
            $applied[] = 'strip_text_colors';
            $content = $new;
        }
    }
    if (in_array('links_to_hash', $fix_ids, true)) {
        $new = preg_replace('/\bhref="https?:\/\/[^"]*"/', 'href="#"', $content);
        if ($new !== $content) {
            $applied[] = 'links_to_hash';
            $content = $new;
        }
    }
    if (in_array('http_to_https', $fix_ids, true)) {
        $new = preg_replace('#\b(href|src|poster|content)="http://#', '$1="https://', $content);
        $new = preg_replace('#url\(\s*(["\']?)http://#', 'url($1https://', $new);
        if ($new !== $content) {
            $applied[] = 'http_to_https';
            $content = $new;
        }
    }
    if (in_array('strip_anchors', $fix_ids, true)) {
        $new = lfw_patterns_fix_strip_anchors($content);
        if ($new !== $content) {
            $applied[] = 'strip_anchors';
            $content = $new;
        }
    }
    if (in_array('px_fontsize_to_scale', $fix_ids, true)) {
        $new = lfw_patterns_fix_px_fontsize_to_scale($content);
        if ($new !== $content) {
            $applied[] = 'px_fontsize_to_scale';
            $content = $new;
        }
    }
    if (in_array('px_to_rem', $fix_ids, true)) {
        $new = lfw_patterns_fix_px_to_rem($content);
        if ($new !== $content) {
            $applied[] = 'px_to_rem';
            $content = $new;
        }
    }

    return $content;
}

/** font-size in px -> slug di scala del tema (var:preset|font-size|...). */
function lfw_patterns_fix_px_fontsize_to_scale($content) {
    $slug = function ($n) {
        $n = (float) $n;
        if ($n < 14) return 'small';
        if ($n < 20) return 'medium';
        if ($n < 28) return 'large';
        if ($n < 44) return 'x-large';
        return 'xx-large';
    };
    $content = preg_replace_callback('/"fontSize":"(\d+(?:\.\d+)?)px"/', function ($m) use ($slug) {
        return '"fontSize":"var:preset|font-size|' . $slug($m[1]) . '"';
    }, $content);
    // numero nudo: "fontSize":48  ->  "fontSize":"var:preset|font-size|x-large"
    $content = preg_replace_callback('/"fontSize":(\d+(?:\.\d+)?)(?=[,}])/', function ($m) use ($slug) {
        return '"fontSize":"var:preset|font-size|' . $slug($m[1]) . '"';
    }, $content);
    $content = preg_replace_callback('/font-size:(\d+(?:\.\d+)?)px/', function ($m) use ($slug) {
        return 'font-size:var(--wp--preset--font-size--' . $slug($m[1]) . ')';
    }, $content);
    return $content;
}

/** spaziature/altezze in px -> rem (0px -> 0). Non tocca border/aspect-ratio. */
function lfw_patterns_fix_px_to_rem($content) {
    $rem = function ($px) {
        $v = (float) $px;
        if (0.0 === $v) {
            return '0';
        }
        $r = rtrim(rtrim(sprintf('%.4f', $v / 16), '0'), '.');
        return $r . 'rem';
    };
    // valori JSON delle chiavi di spaziatura/altezza (con "px")
    $content = preg_replace_callback('/"(top|right|bottom|left|blockGap|padding|margin|height|minHeight|width|minWidth)":"(\d+(?:\.\d+)?)px"/', function ($m) use ($rem) {
        return '"' . $m[1] . '":"' . $rem($m[2]) . '"';
    }, $content);
    // numero nudo: "height":50 / "width":50  (es. core/spacer)  ->  "height":"3.125rem"
    $content = preg_replace_callback('/"(height|width|minHeight|minWidth)":(\d+(?:\.\d+)?)(?=[,}])/', function ($m) use ($rem) {
        return '"' . $m[1] . '":"' . $rem($m[2]) . '"';
    }, $content);
    // CSS inline: padding-*, margin-*, gap, row-gap, column-gap, min-height, height, width
    $content = preg_replace_callback('/\b(padding|margin|gap|row-gap|column-gap|min-height|min-width|height|width)(-[a-z]+)?:(\d+(?:\.\d+)?)px/', function ($m) use ($rem) {
        return $m[1] . $m[2] . ':' . $rem($m[3]);
    }, $content);
    return $content;
}

/** Rimuove gli anchor fissi: attributo "anchor":"x" + l'id="x" corrispondente sul blocco. */
function lfw_patterns_fix_strip_anchors($content) {
    if (!preg_match_all('/"anchor":"([^"]+)"/', $content, $m)) {
        return $content;
    }
    $ids = array_unique($m[1]);
    $content = preg_replace('/,?"anchor":"[^"]+"/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    foreach ($ids as $id) {
        $content = preg_replace('/\s+id="' . preg_quote($id, '/') . '"/', '', $content, 1);
    }
    return $content;
}

/** Blocchi non core noti -> alternativa portabile. */
function lfw_patterns_noncore_alternatives() {
    return array(
        'core/accordion'          => 'core/details',
        'core/accordion-item'     => 'core/details',
        'core/accordion-heading'  => 'core/details (riga <summary>)',
        'core/accordion-panel'    => 'core/details (contenuto)',
        'core/accordion-content'  => 'core/details (contenuto)',
        'outermost/icon-block'    => 'SVG inline in core/html',
        'kadence/rowlayout'       => 'core/group + core/columns',
        'kadence/column'          => 'core/column',
        'kadence/advancedheading' => 'core/heading',
        'generateblocks/container'=> 'core/group',
        'generateblocks/headline' => 'core/heading',
        'generateblocks/button'   => 'core/button',
        'ninja-forms/form'        => 'rimuovi (form non portabile)',
        'contact-form-7/contact-form-7' => 'rimuovi (form non portabile)',
        'jetpack/contact-form'    => 'rimuovi (form non portabile)',
    );
}

/**
 * Rimuove i colori del testo: attributo textColor, "text" dentro color:{},
 * colore del link, classi has-*-color / has-text-color / has-link-color,
 * dichiarazioni inline color: (ma NON background-color / border-color).
 * Il testo torna a ereditare dal tema (§6.2-A).
 */
function lfw_patterns_fix_strip_text_colors($content) {
    // 1. "textColor":"slug"
    $content = preg_replace('/,?"textColor":"[^"]*"/', '', $content);

    // 2. "color":{ ... "text":"..." ... }
    $content = preg_replace_callback('/"color":\{([^{}]*)\}/', function ($m) {
        $inner = preg_replace('/,?"text":"[^"]*"/', '', $m[1]);
        $inner = trim($inner, ", \t");
        return '' === $inner ? '' : '"color":{' . $inner . '}';
    }, $content);

    // 3. colore del testo dei link
    $content = preg_replace('/,?"elements":\{"link":\{"color":\{"text":"[^"]*"\}\}\}/', '', $content);

    // 4. pulizia di oggetti/virgole rimasti vuoti
    $content = preg_replace('/,?"color":\{\}/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    $content = preg_replace('/,\s*\}/', '}', $content);
    $content = preg_replace('/,?"style":\{\}/', '', $content);

    // 5. classi: has-text-color, has-link-color, has-<slug>-color (non ...-background-color)
    $content = preg_replace_callback('/\sclass="([^"]*)"/', function ($m) {
        $tokens = preg_split('/\s+/', trim($m[1]));
        $keep = array();
        foreach ($tokens as $t) {
            if ('has-text-color' === $t || 'has-link-color' === $t) {
                continue;
            }
            if (preg_match('/^has-[a-z0-9-]+(?<!-background)-color$/', $t)) {
                continue;
            }
            $keep[] = $t;
        }
        $cls = implode(' ', array_filter($keep));
        return '' === $cls ? '' : ' class="' . $cls . '"';
    }, $content);

    // 6. inline color: (non background-color, non border-color, non -color qualsiasi)
    $content = preg_replace('/(?<![-\w])color:[^;"}\]]*;?/', '', $content);
    $content = preg_replace('/;\s*;/', ';', $content);
    $content = preg_replace('/style="\s*;?\s*"/', '', $content);
    $content = preg_replace('/\sstyle="\s*"/', '', $content);

    return $content;
}

/**
 * Estrae i colori grezzi distinti presenti nel markup (hex / rgb / hsl).
 *
 * @return string[]
 */
function lfw_patterns_extract_raw_colors($content) {
    $out = array();
    if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', (string) $content, $m)) {
        foreach ($m[0] as $c) {
            $out[strtolower($c)] = true;
        }
    }
    if (preg_match_all('/(?:rg|hs)ba?\([^)]*\)/i', (string) $content, $m)) {
        foreach ($m[0] as $c) {
            $out[preg_replace('/\s+/', '', strtolower($c))] = true;
        }
    }
    return array_keys($out);
}

/**
 * Sostituisce i colori grezzi con i preset del tema secondo $map (raw => slug).
 * slug vuoto = lascia invariato. Agisce su valori JSON e su CSS inline.
 */
function lfw_patterns_apply_colormap($content, $map) {
    foreach ((array) $map as $raw => $slug) {
        $raw = trim((string) $raw);
        $slug = sanitize_key((string) $slug);
        if ('' === $raw || '' === $slug) {
            continue;
        }
        // valore JSON tra virgolette: "#1a1a18" -> "var:preset|color|slug"
        $content = str_replace('"' . $raw . '"', '"var:preset|color|' . $slug . '"', $content);
        $content = str_replace('"' . strtoupper($raw) . '"', '"var:preset|color|' . $slug . '"', $content);
        // CSS inline non quotato: #1a1a18 -> var(--wp--preset--color--slug)
        $content = preg_replace(
            '/(?<![\w"\'|])' . preg_quote($raw, '/') . '(?![\w])/i',
            'var(--wp--preset--color--' . $slug . ')',
            $content
        );
    }
    return $content;
}

/** Rimuove "patternName" dai metadata dei blocchi, pulendo virgole e metadata vuoti. */
function lfw_patterns_fix_strip_patternname($content) {
    $content = preg_replace('/,?"patternName":"[^"]*"/', '', $content);
    // metadata svuotato -> rimuovilo (con la virgola giusta)
    $content = preg_replace('/,"metadata":\{\}/', '', $content);
    $content = preg_replace('/"metadata":\{\},/', '', $content);
    $content = preg_replace('/"metadata":\{\}/', '', $content);
    // virgola iniziale dopo "{" per via della rimozione
    $content = preg_replace('/\{\s*,/', '{', $content);
    return $content;
}

/** Rimuove i token di classe che matchano $token_re da className (JSON) e class="" (HTML). */
function lfw_patterns_fix_strip_class_tokens($content, $token_re) {
    // attributo JSON: "className":"a b c"
    $content = preg_replace_callback(
        '/"className":"([^"]*)"/',
        function ($m) use ($token_re) {
            $cls = trim(preg_replace('/\s+/', ' ', preg_replace($token_re, '', $m[1])));
            return '' === $cls ? '"className":""' : '"className":"' . $cls . '"';
        },
        $content
    );
    // rimuovi eventuale "className":"" (+ virgola)
    $content = preg_replace('/,?"className":""/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);

    // attributo HTML: class="a b c"
    $content = preg_replace_callback(
        '/\sclass="([^"]*)"/',
        function ($m) use ($token_re) {
            $cls = trim(preg_replace('/\s+/', ' ', preg_replace($token_re, '', $m[1])));
            return '' === $cls ? '' : ' class="' . $cls . '"';
        },
        $content
    );
    return $content;
}

/**
 * Rimuove le utility di Twentig: classi "tw-*" (className JSON + class HTML) e
 * attributi di blocco "twStack"/"twGap"/… Senza il plugin Twentig sono inerti.
 */
function lfw_patterns_fix_strip_vendor_classes($content) {
    $content = lfw_patterns_fix_strip_class_tokens($content, '/\btw-[a-z][a-z0-9-]*\b/');
    // attributi JSON del blocco: "twStack":"md-2" | "twGap":true | "twHide":3
    $content = preg_replace('/,?"tw[A-Z][A-Za-z0-9]*":"[^"]*"/', '', $content);
    $content = preg_replace('/,?"tw[A-Z][A-Za-z0-9]*":(?:true|false|null|\d+(?:\.\d+)?)/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    return $content;
}

/**
 * Rimuove gli slug colore fragili di TT4/TT5 (accent-1..6, foreground, tertiary):
 * attributi backgroundColor/textColor, classi has-<slug>-(background-)color e le
 * classi core orfane (has-background / has-text-color), dichiarazioni inline.
 * Il blocco torna a ereditare i colori dal tema di destinazione.
 */
function lfw_patterns_fix_strip_theme_colors($content) {
    $slug_re = '(?:accent-[1-6]|foreground|tertiary)';

    // 1. attributi JSON (backgroundColor/textColor/overlayColor/borderColor/gradient)
    $content = preg_replace('/,?"(?:background|text|overlay|border)Color":"' . $slug_re . '"/', '', $content);
    $content = preg_replace('/,?"gradient":"' . $slug_re . '"/', '', $content);
    // valori var:preset|color|<slug> annidati in style{} (background, text, border.*.color, …)
    $content = preg_replace('/,?"(?:background|text|color)":"var:preset\|color\|' . $slug_re . '"/', '', $content);

    // 2. classi: has-<slug>-background-color / has-<slug>-color + orfane core
    $content = preg_replace_callback('/\sclass="([^"]*)"/', function ($m) use ($slug_re) {
        $tokens = preg_split('/\s+/', trim($m[1]));
        $removed_bg = false;
        $removed_tx = false;
        $keep = array();
        foreach ($tokens as $t) {
            if (preg_match('/^has-' . $slug_re . '-background-color$/', $t)) { $removed_bg = true; continue; }
            if (preg_match('/^has-' . $slug_re . '-color$/', $t))            { $removed_tx = true; continue; }
            $keep[] = $t;
        }
        if ($removed_bg) {
            $keep = array_values(array_diff($keep, array('has-background')));
        }
        if ($removed_tx) {
            $keep = array_values(array_diff($keep, array('has-text-color')));
        }
        $cls = implode(' ', array_filter($keep));
        return '' === $cls ? '' : ' class="' . $cls . '"';
    }, $content);

    // 3. dichiarazioni inline con quei preset (color:, background-color:, border-*-color:, …)
    $content = preg_replace('/[a-z-]*color:var\(--wp--preset--color--' . $slug_re . '\);?/', '', $content);

    // 4. pulizia oggetti/virgole/style vuoti
    $content = preg_replace('/,?"color":\{\}/', '', $content);
    $content = preg_replace('/"(?:top|right|bottom|left)":\{\s*\}/', '', $content);
    $content = preg_replace('/,?"border":\{\s*\}/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    $content = preg_replace('/,\s*\}/', '}', $content);
    $content = preg_replace('/,?"style":\{\}/', '', $content);
    $content = preg_replace('/style="\s*;?\s*"/', '', $content);
    $content = preg_replace('/\sstyle="\s*"/', '', $content);

    return $content;
}

/** Rimuove l'attributo "ref" dai soli core/navigation: l'header/footer adotta il menu del sito di destinazione. */
function lfw_patterns_fix_strip_nav_refs($content) {
    return preg_replace_callback('/<!--\s+wp:navigation\s+\{(?:(?!-->).)*?\}\s+\/?-->/s', function ($m) {
        $s = preg_replace('/,?"ref":\d+/', '', $m[0]);
        return preg_replace('/\{\s*,/', '{', $s);
    }, $content);
}

/** Toglie fontFamily: attributo JSON, classe has-*-font-family, dichiarazione inline. */
function lfw_patterns_fix_strip_fontfamily($content) {
    $content = preg_replace('/,?"fontFamily":"[^"]*"/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    $content = lfw_patterns_fix_strip_class_tokens($content, '/\bhas-[a-z0-9-]+-font-family\b/');
    $content = preg_replace('/font-family:[^;"]*;?/', '', $content);
    // style="" residuo
    $content = preg_replace('/\sstyle="\s*"/', '', $content);
    return $content;
}

/**
 * Data-URI di un segnaposto immagine: SVG inline, nessuna richiesta di rete,
 * nessun apice doppio (sta sia in src="…" sia in un valore JSON). ~0,5 KB.
 *
 * @return string
 */
function lfw_patterns_placeholder_src() {
    static $uri = null;
    if (null !== $uri) {
        return $uri;
    }
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='1200' height='800' viewBox='0 0 1200 800' preserveAspectRatio='xMidYMid slice'>"
         . "<rect width='1200' height='800' fill='#e5e5e5'/>"
         . "<circle cx='330' cy='262' r='96' fill='#cfcfcf'/>"
         . "<path d='M110 704 L430 384 L642 596 L832 430 L1094 700 V704 Z' fill='#cfcfcf'/>"
         . "</svg>";
    $uri = 'data:image/svg+xml,' . rawurlencode($svg);
    return $uri;
}

/**
 * core/image | core/cover con risorsa esterna -> segnaposto visivo.
 * Il blocco resta un core/image/cover vero: si sostituisce nell'editor con un clic.
 * Tocca: attributo JSON "url", tag <img src>, style background-image, srcset/sizes, id allegato.
 */
function lfw_patterns_fix_img_placeholder($content) {
    $ph = lfw_patterns_placeholder_src();

    // attributo JSON del blocco: "url":"http…(immagine)" -> segnaposto
    $content = preg_replace('#"url":"https?://[^"]*\.(?:png|jpe?g|gif|webp|avif|svg|bmp|tiff?)(?:\?[^"]*)?"#i', '"url":"' . $ph . '"', $content);
    // cover/media senza estensione ma con id allegato accanto
    $content = preg_replace('#"url":"https?://[^"]*"(?=[^{}]*"id":\d+)#', '"url":"' . $ph . '"', $content);

    $content = lfw_patterns_strip_attachment_ids($content);

    // tag <img …>: src http -> segnaposto, via srcset/sizes (che puntano ancora fuori)
    $content = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($ph) {
        $tag = $m[0];
        if (!preg_match('#\bsrc="https?://#i', $tag)) {
            return $tag;
        }
        $tag = preg_replace('#\bsrc="https?://[^"]*"#i', 'src="' . $ph . '"', $tag);
        $tag = preg_replace('/\s(?:srcset|sizes)="[^"]*"/i', '', $tag);
        return $tag;
    }, $content);

    // core/cover vecchio formato: style="background-image:url(http…)"
    $content = preg_replace_callback('#background-image:\s*url\(\s*["\']?https?://[^)"\']*["\']?\s*\)#i', function () use ($ph) {
        return 'background-image:url(' . $ph . ')';
    }, $content);

    return $content;
}

/** Rimuove gli ID allegato: "id":N nei metadata JSON e classe wp-image-N (riferimenti morti fuori dal sito d'origine). */
function lfw_patterns_strip_attachment_ids($content) {
    $content = preg_replace('/,?"id":\d+/', '', $content);
    $content = preg_replace('/\{\s*,/', '{', $content);
    $content = lfw_patterns_fix_strip_class_tokens($content, '/\bwp-image-\d+\b/');
    return $content;
}
