<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>账本报告</title>
    <style>
        :root { --navy: #132238; --ink: #1a293b; --muted: #66758a; --line: #dfe6ee; --paper: #fff; --canvas: #f3f6fa; --accent: #3f63d8; --success: #087b5c; --warning: #9a6200; --shadow: 0 18px 44px rgba(23, 42, 68, .09); }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font: 15px/1.55 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", sans-serif; }
        a:focus-visible { outline: 3px solid #ffcb66; outline-offset: 3px; }
        .shell { max-width: 980px; margin: auto; padding: 32px 22px 52px; }
        .hero { padding: 42px; border-radius: 24px; color: #fff; background: radial-gradient(circle at 88% 8%, #587ee9 0, transparent 32%), linear-gradient(125deg, #11243d, #263f70); box-shadow: 0 22px 48px rgba(18, 35, 61, .24); }
        .eyebrow { margin: 0 0 11px; color: #c9d8ff; font-size: 11px; font-weight: 800; letter-spacing: .15em; }
        h1 { margin: 0; font-size: clamp(29px, 5vw, 46px); line-height: 1.12; letter-spacing: -.045em; }
        .hero-copy { max-width: 620px; margin: 14px 0 0; color: #dce6ff; }
        .section-heading { margin: 34px 0 14px; font-size: 19px; }
        .ledger-list { display: grid; gap: 12px; }
        .ledger { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 22px; align-items: center; padding: 22px; border: 1px solid var(--line); border-radius: 18px; background: var(--paper); box-shadow: 0 8px 20px rgba(23, 42, 68, .04); }
        .ledger h2 { margin: 0; font-size: 18px; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 0; color: var(--muted); font-size: 13px; }
        .tag { padding: 3px 8px; border-radius: 999px; background: #edf1f6; font-weight: 700; }
        .availability { color: var(--success); font-weight: 700; }
        .availability.unavailable { color: var(--warning); }
        .button { display: inline-block; padding: 10px 14px; border-radius: 9px; color: #fff; background: var(--accent); font-weight: 800; text-decoration: none; white-space: nowrap; }
        .unavailable-note { color: var(--muted); font-size: 13px; font-weight: 700; }
        .empty { padding: 36px 22px; border: 1px dashed #c8d3e0; border-radius: 18px; color: var(--muted); text-align: center; }
        @media (max-width: 620px) { .shell { padding: 18px 14px 36px; } .hero { padding: 30px 24px; border-radius: 18px; } .ledger { grid-template-columns: 1fr; gap: 14px; } .button { text-align: center; } }
    </style>
</head>
<body>
    <main class="shell">
        <header class="hero">
            <p class="eyebrow">BOOKKEEPING REPORTS</p>
            <h1>账本报告</h1>
            <p class="hero-copy">选择账本即可查看最新的月度报告，无需查找或输入账本编号。</p>
        </header>

        <h2 class="section-heading">可查看的账本</h2>

        @forelse ($ledgers as $ledger)
            <article class="ledger">
                <div>
                    <h2>{{ $ledger['name'] }}</h2>
                    <div class="meta">
                        <span class="tag">{{ $ledger['currency'] }}</span>
                        @if ($ledger['has_latest_report'])
                            <span class="availability">最新报告已生成</span>
                        @else
                            <span class="availability unavailable">最新报告暂不可用</span>
                        @endif
                    </div>
                </div>

                @if ($ledger['has_latest_report'])
                    <a class="button" href="{{ $ledger['report_url'] }}">查看最新报告</a>
                @else
                    <span class="unavailable-note">报告生成后将在这里提供</span>
                @endif
            </article>
        @empty
            <div class="empty">当前没有可查看的账本。</div>
        @endforelse
    </main>
</body>
</html>
