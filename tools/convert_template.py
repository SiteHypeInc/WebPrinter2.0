#!/usr/bin/env python3
"""
WebPrinter Template Converter v1.0
Scans raw Elementor JSON and applies v5.2 markers automatically.

Usage:
  python3 convert_template.py input.json > output.json
  python3 convert_template.py input.json --report  # Show section mapping without converting
"""

import json
import sys
import copy
import re
from collections import defaultdict

# ─── Content schema field patterns ───────────────────────────────────────
# These map common placeholder text patterns to WebPrinter tokens

HEADING_PATTERNS = [
    # Hero / tagline patterns
    (r'(?i)(innovation|creative|solution|agency|company|business|professional|welcome|we are|we\'re)', '{{tagline}}', 'hero'),
    # About patterns
    (r'(?i)(about|who we are|our story|our company|our history)', 'SECTION_LABEL', 'about_label'),
    (r'(?i)(showcasing|our mission|what we do|our approach)', '{{tagline}}', 'about_heading'),
    # Services / features patterns
    (r'(?i)(features|services|what we offer|our services|capabilities)', 'SECTION_LABEL', 'services_label'),
    # Testimonials
    (r'(?i)(testimonial|review|client|trusted|what .* say|voices)', 'SECTION_LABEL', 'testimonials_label'),
    # Pricing
    (r'(?i)(pricing|plans?|packages?)', 'SECTION_LABEL', 'pricing_label'),
    # CTA
    (r'(?i)(contact|get in touch|reach out|let\'s talk|get started|ready)', 'SECTION_LABEL', 'cta_label'),
]

TEXT_PATTERNS = [
    (r'(?i)lorem ipsum', '{{about_long}}'),
    (r'(?i)dolor sit amet', '{{about_long}}'),
]

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
    
    # Check for background images
    bg = settings.get('background_image', {})
    if isinstance(bg, dict) and bg.get('url'):
        info['bg_images'].append(bg['url'])
    
    # Classify widgets
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
    
    # Recurse into children
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
    
    # Check for testimonials
    if any(w in all_text for w in ['testimonial', 'review', 'client', 'trusted voices', 'what our']):
        return 'testimonials'
    
    # Check for pricing
    if any(w in all_text for w in ['pricing', '/month', 'starter', 'enterprise', 'pro plan', 'packages']):
        return 'pricing'
    
    # Check for contact/form
    if info['forms']:
        return 'contact'
    
    # Check for about
    if any(w in all_text for w in ['about', 'who we are', 'our story', 'our company']):
        return 'about'
    
    # Check for services/features
    if any(w in all_text for w in ['features', 'services', 'what we offer', 'capabilities']):
        return 'services'
    
    # First section with background image is likely hero
    if info['bg_images'] and len(info['headings']) <= 4:
        return 'hero'
    
    # Sections with many similar child containers are likely services
    if info['child_containers'] >= 3 and len(info['headings']) >= 3:
        return 'services'
    
    return 'unknown'


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
            # Auto-purge handles images in unknown sections
            mark_images_stock(converted['content'][idx])

    # Swap Pro / 3rd-party-addon widgets with free equivalents so the
    # converted kit renders on a vanilla Elementor install.
    for top in converted.get('content', []):
        swap_unsupported_widgets(top)

    return converted


# ─── Pro / addon widget swap pass ────────────────────────────────────────
# Vanilla Elementor (free) ships these widget types. Anything else falls
# back to a swap — typically a heading or text-editor — so layout doesn't
# collapse on getinstabid-style installs without Pro or 3rd-party addons.

FREE_WIDGET_TYPES = {
    'heading', 'text-editor', 'image', 'button', 'icon', 'icon-box',
    'icon-list', 'spacer', 'divider', 'google_maps', 'video', 'html',
    'shortcode', 'text-path', 'progress', 'tabs', 'accordion', 'toggle',
    'social-icons', 'alert', 'audio', 'menu-anchor', 'sidebar',
    'theme-site-logo', 'theme-site-title', 'theme-page-title',
    'theme-post-title', 'theme-post-excerpt', 'theme-post-content',
    'theme-post-featured-image', 'theme-archive-title',
    # Counter & nested-carousel: free in current Elementor (≥3.20)
    'counter', 'nested-carousel', 'rating', 'testimonial',
}

# Map unsupported widget types → (target_free_type, settings_transformer)
# transformer takes (old_settings) and returns new_settings dict.

def _swap_to_heading(s):
    title = (s.get('heading_dynamic') or s.get('headline_dynamic_text') or [{}])
    if isinstance(title, list) and title:
        first = title[0]
        title_text = first.get('text') if isinstance(first, dict) else str(first)
    else:
        title_text = ''
    title_text = (
        s.get('title') or s.get('heading') or s.get('text')
        or s.get('header_size_text') or title_text or '{{tagline}}'
    )
    out = {'title': title_text, 'header_size': s.get('header_size', 'h2')}
    if s.get('align'): out['align'] = s['align']
    if s.get('title_color'): out['title_color'] = s['title_color']
    return out

def _swap_to_text_editor(s):
    body = s.get('editor') or s.get('text') or s.get('description') or s.get('content', '')
    if not body:
        body = '<p>{{about_short}}</p>'
    return {'editor': body}

def _swap_to_button(s):
    return {
        'text': s.get('text') or s.get('button_text') or '{{cta.primary_text}}',
        'link': s.get('link', {'url': ''}),
    }

def _swap_to_shortcode_stub(s):
    # Free Elementor has a `shortcode` widget; emit an empty shortcode that
    # renders nothing but keeps the layout slot. Editors can drop a real
    # shortcode (e.g. CF7, HFE nav) in post-deploy.
    return {'shortcode': s.get('shortcode', '')}

WIDGET_SWAPS = {
    'animated-headline': ('heading', _swap_to_heading),
    'form': ('shortcode', _swap_to_shortcode_stub),
    'nav-menu': ('shortcode', _swap_to_shortcode_stub),
    'posts': ('text-editor', _swap_to_text_editor),
    'portfolio': ('text-editor', _swap_to_text_editor),
    'slides': ('image', lambda s: {'image': {'url': '', 'id': 0}, '_wp_stock': 'hero'}),
    'gallery': ('text-editor', _swap_to_text_editor),
    'price-list': ('text-editor', _swap_to_text_editor),
    'price-table': ('text-editor', _swap_to_text_editor),
    'flip-box': ('icon-box', lambda s: {
        'title_text': s.get('title_text_a') or s.get('title') or '',
        'description_text': s.get('description_text_a') or s.get('description') or '',
    }),
    'call-to-action': ('text-editor', _swap_to_text_editor),
    'reviews': ('text-editor', _swap_to_text_editor),
    'testimonial-carousel': ('testimonial', lambda s: s),
    'media-carousel': ('nested-carousel', lambda s: s),
    'image-carousel': ('nested-carousel', lambda s: s),
    'lottie': ('image', lambda s: {'image': {'url': '', 'id': 0}, '_wp_stock': 'generic'}),
    'countdown': ('heading', _swap_to_heading),
    'hotspot': ('image', lambda s: {'image': {'url': '', 'id': 0}, '_wp_stock': 'generic'}),
    # 3rd-party addon prefixes (Merkulove `mdp-*`, Qi `qi_*`, ThemePalace `tp-*`)
    # Handled dynamically in swap_unsupported_widgets.
}

ADDON_PREFIXES = ('mdp-', 'qi_', 'tp-', 'eael-', 'wpr-', 'jet-', 'crocoblock-')

def _addon_default_swap(wtype, s):
    """Pick a sensible free swap for unknown 3rd-party addon widgets."""
    name = wtype.lower()
    if 'button' in name or 'btn' in name:
        return ('button', _swap_to_button(s))
    if 'menu' in name or 'nav' in name or 'crumb' in name:
        return ('shortcode', _swap_to_shortcode_stub(s))
    if 'list' in name or 'team' in name or 'member' in name or 'showcase' in name:
        return ('icon-box', {
            'title_text': s.get('title') or s.get('name') or '',
            'description_text': s.get('description') or s.get('text') or '',
        })
    if 'image' in name or 'gallery' in name or 'media' in name or 'photo' in name:
        return ('image', {'image': {'url': '', 'id': 0}, '_wp_stock': 'generic'})
    if 'heading' in name or 'title' in name or 'header' in name:
        return ('heading', _swap_to_heading(s))
    return ('text-editor', _swap_to_text_editor(s))


def swap_unsupported_widgets(el):
    """Recursively rewrite widgets whose types aren't in vanilla Elementor."""
    if not isinstance(el, dict):
        return
    wtype = el.get('widgetType', '')
    if wtype and wtype not in FREE_WIDGET_TYPES:
        s = el.get('settings', {}) or {}
        # Preserve any WebPrinter markers from the original settings.
        markers = {k: v for k, v in s.items() if k.startswith('_wp_')}
        if wtype in WIDGET_SWAPS:
            new_type, transformer = WIDGET_SWAPS[wtype]
            el['widgetType'] = new_type
            new_s = transformer(s)
            new_s.update(markers)
            el['settings'] = new_s
        elif any(wtype.startswith(p) for p in ADDON_PREFIXES):
            new_type, new_settings = _addon_default_swap(wtype, s)
            el['widgetType'] = new_type
            new_settings.update(markers)
            el['settings'] = new_settings
        # Other unknown widgets left alone — may be from active plugins on the target.
    for child in el.get('elements', []) or []:
        swap_unsupported_widgets(child)


def convert_hero(el):
    """Convert hero section: tagline, about_short, hero background."""
    settings = el.get('settings', {})
    
    # Mark background image as hero
    if settings.get('background_image', {}).get('url'):
        settings['_wp_stock'] = 'hero'
    
    # Walk children
    first_heading = True
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading' and first_heading:
            s['title'] = '{{tagline}}'
            first_heading = False
        elif wtype == 'text-editor' and is_lorem(s.get('editor', '')):
            s['editor'] = '{{about_short}}'
        elif wtype == 'image':
            if s.get('image', {}).get('url'):
                s['_wp_stock'] = 'hero'
                s['image'] = {'url': '', 'id': 0}
        elif wtype == 'button':
            s['text'] = '{{cta.primary_text}}'


def convert_about(el):
    """Convert about section: about_long, about image, credentials."""
    heading_count = 0
    text_count = 0
    
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading':
            heading_count += 1
            title = s.get('title', '').lower()
            if any(w in title for w in ['about', 'who we']):
                pass  # Keep section labels
            elif heading_count <= 2:
                s['title'] = '{{tagline}}'
        elif wtype == 'text-editor':
            text_count += 1
            text = s.get('editor', '').lower()
            if any(w in text for w in ['about', '<p>about</p>']):
                pass  # Keep section labels
            elif is_lorem(text) or text_count == 1:
                s['editor'] = '{{about_long}}'
        elif wtype == 'image':
            if s.get('image', {}).get('url'):
                s['_wp_img'] = 'about'
                s['image'] = {'url': '', 'id': 0}
        elif wtype == 'button':
            s['text'] = '{{cta.primary_text}}'
        elif wtype == 'icon-list':
            convert_icon_list_to_credentials(s)


def convert_services(el):
    """Convert services section: find repeated card patterns, apply _wp_repeat."""
    # Find the section label and main heading
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading':
            title = s.get('title', '').lower()
            if any(w in title for w in ['features', 'services', 'what we offer']):
                pass  # Keep
            elif any(w in title for w in ['showcasing', 'our']):
                s['title'] = '{{tagline}}'
        elif wtype == 'text-editor' and is_lorem(s.get('editor', '')):
            s['editor'] = '{{about_short}}'
    
    # Find repeated card containers and mark first with _wp_repeat
    find_and_mark_repeats(el, 'services')


def convert_testimonials(el):
    """Convert testimonials section: find quote cards, apply _wp_repeat."""
    # Mark section heading
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading':
            title = s.get('title', '')
            if title.startswith('"') or title.startswith('\u201c'):
                # This is a quote — will be handled by repeat
                pass
    
    # Find repeated quote containers
    find_and_mark_repeats(el, 'testimonials')


def convert_pricing(el):
    """Convert pricing section with _wp_if so it can be hidden when no pricing data."""
    el['settings']['_wp_if'] = 'pricing'
    mark_images_stock(el)


def convert_contact(el):
    """Convert contact/form section."""
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading':
            title = s.get('title', '').lower()
            if any(w in title for w in ['contact', 'get in touch', 'reach', 'ready']):
                s['title'] = '{{cta.primary_text}}'
        elif wtype == 'text-editor' and is_lorem(s.get('editor', '')):
            s['editor'] = '{{about_short}}'


# ─── Repeat detection ────────────────────────────────────────────────────

def find_and_mark_repeats(el, array_name):
    """Find containers that look like repeated cards (similar structure).
    Keep the first, mark with _wp_repeat, delete the rest."""
    
    containers = el.get('elements', [])
    if not containers:
        return
    
    # Look for groups of sibling containers with similar widget structures
    for container in containers:
        children = container.get('elements', [])
        if len(children) < 2:
            continue
        
        # Get widget signatures for each child
        signatures = []
        for child in children:
            sig = get_widget_signature(child)
            signatures.append(sig)
        
        # Find groups with identical signatures (repeated cards)
        sig_counts = defaultdict(list)
        for i, sig in enumerate(signatures):
            sig_counts[sig].append(i)
        
        for sig, indices in sig_counts.items():
            if len(indices) >= 2 and sig:  # 2+ similar containers = repeating pattern
                # Mark the first one with _wp_repeat
                first_idx = indices[0]
                first_child = children[first_idx]
                first_child.setdefault('settings', {})['_wp_repeat'] = array_name
                first_child['settings']['_wp_repeat_max'] = 8
                
                # Apply tokens inside the first card
                apply_card_tokens(first_child, array_name)
                
                # Mark images as stock inside the first card
                mark_images_stock(first_child, category='action')
                
                # Remove duplicates (reverse order to preserve indices)
                for idx in sorted(indices[1:], reverse=True):
                    del children[idx]
                
                break  # Only process one repeat group per container level
        
        # Recurse into remaining children
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


def apply_card_tokens(el, array_name):
    """Apply _item tokens inside a repeated card."""
    heading_count = 0
    text_count = 0
    
    for child in walk_widgets(el):
        wtype = child.get('widgetType', '')
        s = child.get('settings', {})
        
        if wtype == 'heading':
            heading_count += 1
            if heading_count == 1:
                if array_name == 'testimonials':
                    s['title'] = '{{testimonials._item.quote}}'
                else:
                    s['title'] = '{{' + array_name + '._item.name}}'
        elif wtype == 'text-editor':
            text_count += 1
            if text_count == 1:
                if array_name == 'testimonials':
                    s['editor'] = '{{testimonials._item.author}}'
                else:
                    s['editor'] = '{{' + array_name + '._item.description}}'


# ─── Utility functions ───────────────────────────────────────────────────

def walk_widgets(el):
    """Generator that yields all widget elements in a tree."""
    if not isinstance(el, dict):
        return
    if el.get('widgetType'):
        yield el
    for child in el.get('elements', []):
        yield from walk_widgets(child)


def is_lorem(text):
    """Check if text contains lorem ipsum placeholder content."""
    if not text:
        return False
    t = text.lower()
    return 'lorem ipsum' in t or 'dolor sit amet' in t or 'consectetur adipiscing' in t


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
    
    # Keep first item, add _wp_repeat, delete rest
    if len(icon_list) > 0:
        first = icon_list[0]
        first['_wp_repeat'] = 'credentials'
        first['text'] = '{{credentials._item.name}}'
        settings['icon_list'] = [first]


# ─── Report mode ─────────────────────────────────────────────────────────

def report(data):
    """Print a human-readable section map of the template."""
    content = data.get('content', [])
    sections = detect_sections(content)
    
    print(f"\n{'='*60}")
    print(f"TEMPLATE: {data.get('title', 'Unknown')}")
    print(f"SECTIONS: {len(sections)}")
    print(f"{'='*60}\n")
    
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
        print("Usage: python3 convert_template.py input.json [--report]")
        sys.exit(1)
    
    with open(sys.argv[1], 'r') as f:
        data = json.load(f)
    
    if '--report' in sys.argv:
        report(data)
    else:
        converted = convert_template(data)
        print(json.dumps(converted, indent=2, ensure_ascii=False))
