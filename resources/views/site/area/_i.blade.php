@php
    $paths = [
        'bag' => '<path d="M6 7h12l-1 13H7L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
        'repeat' => '<path d="M4 12a8 8 0 0 1 13.5-5.8L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-13.5 5.8L4 16"/><path d="M4 20v-4h4"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4V5Z"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M8 3v4M16 3v4"/>',
        'news' => '<path d="M5 4h11l3 3v13H5V4Z"/><path d="M8 10h8M8 14h8M8 17h5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'wallet' => '<path d="M4 7h15a1 1 0 0 1 1 1v11H5a1 1 0 0 1-1-1V7Z"/><path d="M4 7l12-3v3"/><circle cx="16" cy="13.5" r="1"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.3-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18 14.8c1.7.7 3 2.4 3.5 5.2"/>',
        'tree' => '<circle cx="12" cy="5" r="2.2"/><circle cx="6" cy="19" r="2.2"/><circle cx="18" cy="19" r="2.2"/><path d="M12 7v5M12 12H6v5M12 12h6v5"/>',
        'download' => '<path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'euro' => '<path d="M17 6.5a6.5 6.5 0 1 0 0 11"/><path d="M5 10h8M5 14h8"/>',
        'star' => '<path d="M12 4l2.4 5 5.4.7-4 3.8 1 5.4L12 16.2 7.2 19l1-5.4-4-3.8 5.4-.7L12 4Z"/>',
        'alert' => '<path d="M12 4l9 16H3L12 4Z"/><path d="M12 10v4M12 17v.5"/>',
        'doc' => '<path d="M6 3h8l4 4v14H6V3Z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'qr' => '<rect x="4" y="4" width="6" height="6"/><rect x="14" y="4" width="6" height="6"/><rect x="4" y="14" width="6" height="6"/><path d="M14 14h3v3h-3zM19 14v1M14 19h1M18 19h2"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6"/>',
    ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $paths[$n] ?? '' !!}</svg>
