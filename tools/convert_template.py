#!/usr/bin/env python3
"""
WebPrinter Template Converter v2.0 — Aggressive Tokenization
Scans raw Elementor JSON and applies v5.2 markers automatically.

v2 change: every `heading` and `text-editor` widget is replaced with a token
unless its text is on the SECTION_LABELS whitelist. Token choice depends on
section context (hero/about/services/testimonials/contact/pricing/unknown)
and on whether the widget sits inside a `_wp_repeat` card.

Usage:
  python3 convert_template.py input.json > output.json
  python3 convert_template.py input.json --report  # Show section mapping without converting
  python3 convert_template.py input.json --audit   # Convert, then report any non-label heading/text-editor that still contains literal text
"""

import json
import sys
import copy
import re
from collections import defaultdict
import html as _html


# ─── Section labels (kept as-is, NOT tokenized) ──────────────────────────
# Lowercased, HTML-stripped, punctuation-stripped. Match is exact.
SECTION_LABELS = {
    # About
    'about', 'about us', 'who we are', 'our story', 'our company', 'our history',
    'our mission', 'our vision', 'why us', 'why choose us',
    # Services / features
    'services', 'our services', 'what we offer', 'what we do', 'features',
    'capabilities', 'our value', 'our values', 'solutions', 'expertise',
    # Testimonials
    'testimonials', 'reviews', 'what our clients say', 'what people say',
    'trusted voices', 'client testimonials', 'customer reviews', 'voices',
    'happy clients', 'happy customers',
    # Pricing
    'pricing', 'plans', 'packages', 'our pricing', 'price',
    # Process
    'process', 'how it works', 'our process', '3 simple steps', 'simple steps',
    'steps', 'workflow',
    # Contact / CTA
    'contact', 'contact us', 'get in touch', 'reach out', "let's talk",
    "let's get started", 'ready', 'get started', 'cta', 'lets talk',
    'lets get started',
    # FAQ
    'faq', 'faqs', 'frequently asked questions', 'questions',
    # Team
    'team', 'our team', 'meet the team', 'our experts', 'experts',
    # Blog / News
    'blog', 'articles', 'news', 'insights', 'latest news', 'latest articles',
    # Gallery / Portfolio
    'gallery', 'portfolio', 'projects', 'case studies', 'our projects',
    'our work', 'work', 'recent work', 'latest projects',
    # Credentials
    'credentials', 'awards', 'certifications', 'partners', 'trusted by',
    'clients',
    # Generic UI labels (often used as eyebrow text inside cards)
    'new', 'popular', 'best', 'top', 'free', 'premium',
}


HEADING_TOKEN_BY_SECTION = {
    'hero': '{{tagline}}',
    'about': '{{tagline}}',
    'services': '{{tagline}}',
    'testimonials': '{{tagline}}',
    'pricing': '{{tagline}}',
    'contact': '{{cta.primary_text}}',
    'unknown': '{{tagline}}',
}

TEXT_TOKEN_BY_SECTION = {
    'hero': '{{about_short}}',
    'about': '{{about_long}}',
    'services': '{{about_short}}',
    'testimonials': '{{about_short}}',
    'pricing': '{{about_short}}',
    'contact': '{{about_short}}',
    'unknown': '{{about_short}}',
}


def normalize_text(raw):
    """Strip HTML, unescape entities, lowercase, collapse whitespace + punctuation."""
    if not raw or not isinstance(raw, str):
        return ''
    t = _html.unescape(raw)
    t = re.sub(r'<[^>]+>', ' ', t)
    t = t.lower().strip()
    t = re.sub(r"[^\w\s']", ' ', t)
    t = re.sub(r'\s+', ' ', t).strip()
    return t


_LABEL_NUMERIC_RE = re.compile(r'^(case|step|no|number|chapter|part)?\s*\d+$')


def is_section_label(raw):
    """True if the raw text should be preserved as a generic section label.

    Catches: empty strings, the whitelist, short numeric/decorative labels
    like "1", "case 1", "step 3", and any text shorter than 3 chars.
    """
    n = normalize_text(raw)
    if not n:
        return True
    if len(n) < 3:
        return True
    if _LABEL_NUMERIC_RE.match(n):
        return True
    return n in SECTION_LABELS


def is_token_text(raw):
    """True if the value is already a {{token}}-only string (don't double-tokenize)."""
    if not raw:
        return False
    t = re.sub(r'<[^>]+>', '', raw).strip()
    return bool(re.fullmatch(r'\{\{[^{}]+\}\}', t))


# ─── Section detection ───────────────────────────────────────────────────

def detect_sections(content):
    """Walk the top-level containers and classify each as a section type."""
    sections = []
    for i, el in enumerate(content):
        info = analyze_container(el)
        sections.append({
            'index': i,
            'type': classify_section(info),
            'info': info,
        })
    return sections


def analyze_container(el, depth=0):
    """Recursively gather info about what's inside a container."""
    info = {
        'headings': [],
        'texts': [],
        'images': [],
        'bg_images': [],
        'widgets': [],
        'buttons': [],
        'icon_lists': [],
        'forms': [],
        'counters': [],
        'repeated_patterns': [],
        'child_containers': 0,
    }

    if not isinstance(el, dict):
        return info

    settings = el.get('settings', {})
    etype = el.get('elType', '')
    wtype = el.get('widgetType', '')

    bg = settings.get('background_image', {})
    if isinstance(bg, dict) and bg.get('url'):
        info['bg_images'].append(bg['url'])

    if wtype:
        info['widgets'].append(wtype)
        if wtype == 'heading':
            info['headings'].append(settings.get('title', ''))
        elif wtype == 'text-editor':
            info['texts'].append(settings.get('editor', '')[:200])
        elif wtype == 'image':
            url = settings.get('image', {}).get('url', '')
            if url:
                info['images'].append(url)
        elif wtype == 'button':
            info['buttons'].append(settings.get('text', ''))
        elif wtype == 'icon-list':
            info['icon_lists'].append(settings.get('icon_list', []))
        elif wtype in ('rform', 'form', 'wpforms-widget', 'fluentform'):
            info['forms'].append(wtype)
        elif wtype in ('counter', 'rkit-counter'):
            info['counters'].append(settings.get('suffix', ''))

    if etype == 'container' and depth > 0:
        info['child_containers'] += 1

    for child in el.get('elements', []):
        child_info = analyze_container(child, depth + 1)
        for key in info:
            if isinstance(info[key], list):
                info[key].extend(child_info[key])
            elif isinstance(info[key], int):
                info[key] += child_info[key]

    return info


def classify_section(info):
    """Classify a section based on its contents."""
    headings_text = ' '.join(info['headings']).lower()
    texts_text = ' '.join(info['texts']).lower()
    all_text = headings_text + ' ' + texts_text

    if any(w in all_text for w in ['testimonial', 'review', 'client', 'trusted voices', 'what our']):
        return 'testimonials'

    if any(w in all_text for w in ['pricing', '/month', 'starter', 'enterprise', 'pro plan', 'packages']):
        return 'pricing'

    if info['forms']:
        return 'contact'

    if any(w in all_text for w in ['about', 'who we are', 'our story', 'our company']):
        return 'about'

    if any(w in all_text for w in ['features', 'services', 'what we offer', 'capabilities']):
        return 'services'

    if info['bg_images'] and len(info['headings']) <= 4:
        return 'hero'

    if info['child_containers'] >= 3 and len(info['headings']) >= 3:
        return 'services'

    return 'unknown'


# ─── Aggressive tokenization core ────────────────────────────────────────

def aggressive_tokenize_section(el, stype):
    """Walk every heading and text-editor in this section that is NOT already
    inside a `_wp_repeat` container, and replace its text with the section-
    context token unless the text is a generic section label.

    Skips widgets/containers marked with `_wp_keep`.
    """
    h_token = HEADING_TOKEN_BY_SECTION.get(stype, '{{tagline}}')
    t_token = TEXT_TOKEN_BY_SECTION.get(stype, '{{about_short}}')

    def walk(node, in_repeat):
        if not isinstance(node, dict):
            return
        s = node.get('settings', {})
        if s.get('_wp_keep'):
            return
        if s.get('_wp_repeat'):
            in_repeat = True
        wtype = node.get('widgetType', '')
        if not in_repeat and wtype:
            if wtype == 'heading':
                title = s.get('title', '')
                if not is_token_text(title) and not is_section_label(title):
                    s['title'] = h_token
            elif wtype == 'text-editor':
                editor = s.get('editor', '')
                # Elementor renders default lorem-ipsum when editor is missing/empty,
                # so we must always force a token (do NOT treat empty as a section label).
                if not editor or not editor.strip():
                    s['editor'] = t_token
                elif not is_token_text(editor) and not is_section_label(editor):
                    s['editor'] = t_token
            elif wtype == 'jkit_heading':
                tokenize_jkit_heading(s, h_token, t_token)
        for c in node.get('elements', []):
            walk(c, in_repeat)

    walk(el, in_repeat=False)


# ─── Conversion engine ───────────────────────────────────────────────────

def convert_template(data):
    """Main conversion function. Takes raw Elementor JSON, returns marked version."""
    content = data.get('content', [])
    sections = detect_sections(content)

    converted = copy.deepcopy(data)
    converted['metadata'] = converted.get('metadata', {})
    converted['metadata']['_meta_converted'] = 'webprinter-v5.2'

    for section in sections:
        idx = section['index']
        stype = section['type']

        if stype == 'hero':
            convert_hero(converted['content'][idx])
        elif stype == 'about':
            convert_about(converted['content'][idx])
        elif stype == 'services':
            convert_services(converted['content'][idx])
        elif stype == 'testimonials':
            convert_testimonials(converted['content'][idx])
        elif stype == 'pricing':
            convert_pricing(converted['content'][idx])
        elif stype == 'contact':
            convert_contact(converted['content'][idx])
        else:
            convert_unknown(converted['content'][idx])

    return converted


def convert_hero(el):
    """Hero: bg → stock, first image → _wp_img:hero, buttons → cta, headings → tagline, text → about_short."""
    settings = el.get('settings', {})
    if settings.get('background_image', {}).get('url'):
        settings['_wp_stock'] = 'hero'

    first_image = True
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'image' and s.get('image', {}).get('url'):
            if first_image:
                s['_wp_img'] = 'hero'
                first_image = False
            else:
                s['_wp_stock'] = 'hero'
            s['image'] = {'url': '', 'id': 0}
        elif wtype == 'button':
            s['text'] = '{{cta.primary_text}}'

    aggressive_tokenize_section(el, 'hero')


def convert_about(el):
    """About: first image → _wp_img:about, icon-list → credentials, buttons → cta,
    headings → tagline, text → about_long."""
    first_image = True
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'image' and s.get('image', {}).get('url'):
            if first_image:
                s['_wp_img'] = 'about'
                first_image = False
            else:
                s['_wp_stock'] = 'team'
            s['image'] = {'url': '', 'id': 0}
        elif wtype == 'button':
            s['text'] = '{{cta.primary_text}}'
        elif wtype == 'icon-list':
            convert_icon_list_to_credentials(s)

    aggressive_tokenize_section(el, 'about')


def convert_services(el):
    """Services: find repeated cards → _wp_repeat:services (tokens inside),
    section-level headings → tagline, text → about_short, stray images → stock."""
    find_and_mark_repeats(el, 'services')

    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'button':
            s['text'] = '{{cta.primary_text}}'

    mark_images_stock(el, category='action')
    aggressive_tokenize_section(el, 'services')


def convert_testimonials(el):
    """Testimonials: find repeated cards → _wp_repeat:testimonials (quote/author tokens),
    wrap section in _wp_if:testimonials so it disappears when source has none."""
    find_and_mark_repeats(el, 'testimonials')
    el.setdefault('settings', {})['_wp_if'] = 'testimonials'
    aggressive_tokenize_section(el, 'testimonials')


def convert_pricing(el):
    """Pricing: hide whole section with _wp_if:pricing, mark images stock,
    still tokenize headings/text in case the section is ever shown."""
    el.setdefault('settings', {})['_wp_if'] = 'pricing'
    mark_images_stock(el)
    aggressive_tokenize_section(el, 'pricing')


def convert_contact(el):
    """Contact/form: headings → cta.primary_text, text → about_short."""
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'button':
            s['text'] = '{{cta.primary_text}}'

    aggressive_tokenize_section(el, 'contact')


def convert_unknown(el):
    """Unknown section: tokenize all headings/text generically + mark images stock.
    Prevents niche copy from leaking through sections the classifier didn't recognize."""
    mark_images_stock(el)
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'button':
            s['text'] = '{{cta.primary_text}}'
    aggressive_tokenize_section(el, 'unknown')


# ─── Repeat detection ────────────────────────────────────────────────────

def find_and_mark_repeats(el, array_name):
    """Find containers that look like repeated cards (similar structure).
    Keep the first, mark with _wp_repeat, delete the rest."""

    containers = el.get('elements', [])
    if not containers:
        return

    for container in containers:
        children = container.get('elements', [])
        if len(children) < 2:
            continue

        signatures = []
        for child in children:
            sig = get_widget_signature(child)
            signatures.append(sig)

        sig_counts = defaultdict(list)
        for i, sig in enumerate(signatures):
            sig_counts[sig].append(i)

        for sig, indices in sig_counts.items():
            if len(indices) >= 2 and sig:
                first_idx = indices[0]
                first_child = children[first_idx]
                first_child.setdefault('settings', {})['_wp_repeat'] = array_name
                first_child['settings']['_wp_repeat_max'] = 8

                apply_card_tokens(first_child, array_name)
                mark_images_stock(first_child, category='action')

                for idx in sorted(indices[1:], reverse=True):
                    del children[idx]

                break

        for child in children:
            if child.get('elType') == 'container':
                find_and_mark_repeats(child, array_name)


def get_widget_signature(el):
    """Get a signature string representing the widget structure of a container."""
    if el.get('elType') != 'container':
        wtype = el.get('widgetType', '')
        return wtype if wtype else ''

    child_sigs = []
    for child in el.get('elements', []):
        sig = get_widget_signature(child)
        if sig:
            child_sigs.append(sig)

    return '|'.join(child_sigs) if child_sigs else ''


CARD_HEADING_TOKEN = {
    'services': '{{services._item.name}}',
    'testimonials': '{{testimonials._item.quote}}',
    'team': '{{team._item.name}}',
    'process_steps': '{{process_steps._item.title}}',
    'credentials': '{{credentials._item.name}}',
    'service_areas': '{{service_areas._item.name}}',
}

CARD_TEXT_TOKEN = {
    'services': '{{services._item.description}}',
    'testimonials': '{{testimonials._item.author}}',
    'team': '{{team._item.title}}',
    'process_steps': '{{process_steps._item.description}}',
    'credentials': '{{credentials._item.name}}',
    'service_areas': '{{service_areas._item.name}}',
}


def apply_card_tokens(el, array_name):
    """Aggressively tokenize ALL headings and text-editors inside a repeat card.

    - Every non-label heading → card heading token (e.g. {{services._item.name}})
    - Every non-label text-editor → card text token (e.g. {{services._item.description}})
    - Section labels and `_wp_keep` widgets are left alone
    """
    h_token = CARD_HEADING_TOKEN.get(array_name, '{{' + array_name + '._item.name}}')
    t_token = CARD_TEXT_TOKEN.get(array_name, '{{' + array_name + '._item.description}}')

    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if s.get('_wp_keep'):
            continue
        if wtype == 'heading':
            title = s.get('title', '')
            if not is_token_text(title) and not is_section_label(title):
                s['title'] = h_token
        elif wtype == 'text-editor':
            editor = s.get('editor', '')
            # Empty/missing editor → Elementor lorem-ipsum default, so force a token.
            if not editor or not editor.strip():
                s['editor'] = t_token
            elif not is_token_text(editor) and not is_section_label(editor):
                s['editor'] = t_token
        elif wtype == 'jkit_heading':
            tokenize_jkit_heading(s, h_token, t_token)
        elif wtype == 'button':
            s['text'] = '{{cta.primary_text}}'


# ─── JetElements jkit_heading helper ─────────────────────────────────────

# jkit_heading is JetElements' main-heading widget. It has six text fields:
#   sg_title_before / sg_title_focused / sg_title_text / sg_title_after  → headline
#   sg_subtitle_heading                                                  → subtitle
#   sg_shadow_content                                                    → decorative shadow text
# Elementor's converter (`heading` / `text-editor`) doesn't touch any of these,
# so we tokenize the visible-text fields here.
JKIT_HEADLINE_FIELDS = ('sg_title_before', 'sg_title_focused', 'sg_title_text', 'sg_title_after')

def tokenize_jkit_heading(s, h_token, t_token):
    """Tokenize the six visible-text fields on a jkit_heading widget.

    The headline is assembled from sg_title_before/_focused/_text/_after — we put
    the section heading token on `sg_title_text` and blank the rest so the rendered
    headline matches the token output. Subtitle → text token. Shadow → blanked.
    """
    # Headline: blank decorative parts, put token on the main `sg_title_text`.
    for f in ('sg_title_before', 'sg_title_focused', 'sg_title_after'):
        if f in s and isinstance(s[f], str) and s[f].strip() and not is_section_label(s[f]):
            s[f] = ''
    title = s.get('sg_title_text', '')
    if not isinstance(title, str) or not title.strip() or (
        not is_token_text(title) and not is_section_label(title)
    ):
        s['sg_title_text'] = h_token

    sub = s.get('sg_subtitle_heading', '')
    if isinstance(sub, str) and sub.strip() and not is_token_text(sub) and not is_section_label(sub):
        s['sg_subtitle_heading'] = t_token

    shadow = s.get('sg_shadow_content', '')
    if isinstance(shadow, str) and shadow.strip() and not is_section_label(shadow):
        s['sg_shadow_content'] = ''


# ─── Utility functions ───────────────────────────────────────────────────

def walk_widgets(el):
    """Generator that yields all widget elements in a tree."""
    if not isinstance(el, dict):
        return
    if el.get('widgetType'):
        yield el
    for child in el.get('elements', []):
        yield from walk_widgets(child)


def mark_images_stock(el, category='generic'):
    """Mark all unmarked image widgets with _wp_stock."""
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        if wtype == 'image' and not s.get('_wp_img') and not s.get('_wp_stock') and not s.get('_wp_keep'):
            if s.get('image', {}).get('url'):
                s['_wp_stock'] = category
                s['image'] = {'url': '', 'id': 0}


def convert_icon_list_to_credentials(settings):
    """Convert icon-list widget items to _wp_repeat credentials."""
    icon_list = settings.get('icon_list', [])
    if not icon_list:
        return

    settings['_wp_if'] = 'credentials'

    if len(icon_list) > 0:
        first = icon_list[0]
        first['_wp_repeat'] = 'credentials'
        first['text'] = '{{credentials._item.name}}'
        settings['icon_list'] = [first]


# ─── Audit mode ──────────────────────────────────────────────────────────

def audit(data):
    """Convert, then walk the result and report any heading/text-editor that
    still holds literal (non-token, non-label) text. Exit code 1 if any leaks."""
    converted = convert_template(data)
    leaks = []

    def walk(node, path):
        if not isinstance(node, dict):
            return
        wtype = node.get('widgetType', '')
        s = node.get('settings', {})
        if wtype == 'heading':
            t = s.get('title', '')
            if t and not is_token_text(t) and not is_section_label(t):
                leaks.append(('heading.title', path, t[:140]))
        elif wtype == 'text-editor':
            t = s.get('editor', '')
            if t and not is_token_text(t) and not is_section_label(t):
                leaks.append(('text-editor.editor', path, t[:140]))
        elif wtype == 'jkit_heading':
            for f in JKIT_HEADLINE_FIELDS + ('sg_subtitle_heading', 'sg_shadow_content'):
                v = s.get(f, '')
                if isinstance(v, str) and v.strip() and not is_token_text(v) and not is_section_label(v):
                    leaks.append((f'jkit_heading.{f}', path, v[:140]))
        for i, c in enumerate(node.get('elements', [])):
            walk(c, path + f'/elements[{i}]')

    for i, sec in enumerate(converted.get('content', [])):
        walk(sec, f'content[{i}]')

    print(f"\nAUDIT — {data.get('title', 'Unknown')}")
    print(f"Leaks: {len(leaks)}")
    for kind, path, txt in leaks:
        print(f"  • {kind} @ {path}")
        print(f"    {txt!r}")
    return 0 if not leaks else 1


# ─── Report mode ─────────────────────────────────────────────────────────

def report(data):
    """Print a human-readable section map of the template."""
    content = data.get('content', [])
    sections = detect_sections(content)

    print(f"\n{'=' * 60}")
    print(f"TEMPLATE: {data.get('title', 'Unknown')}")
    print(f"SECTIONS: {len(sections)}")
    print(f"{'=' * 60}\n")

    for s in sections:
        info = s['info']
        print(f"Section {s['index'] + 1}: {s['type'].upper()}")
        print(f"  Headings:    {len(info['headings'])} — {info['headings'][:3]}")
        print(f"  Text blocks: {len(info['texts'])}")
        print(f"  Images:      {len(info['images'])} widget + {len(info['bg_images'])} background")
        print(f"  Buttons:     {len(info['buttons'])}")
        print(f"  Icon lists:  {len(info['icon_lists'])}")
        print(f"  Forms:       {len(info['forms'])}")
        print(f"  Counters:    {len(info['counters'])}")
        print(f"  Containers:  {info['child_containers']}")
        print()


# ─── Main ────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python3 convert_template.py input.json [--report|--audit]")
        sys.exit(1)

    with open(sys.argv[1], 'r') as f:
        data = json.load(f)

    if '--report' in sys.argv:
        report(data)
    elif '--audit' in sys.argv:
        sys.exit(audit(data))
    else:
        converted = convert_template(data)
        print(json.dumps(converted, indent=2, ensure_ascii=False))
