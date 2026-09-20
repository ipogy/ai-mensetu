-- seed.sql
INSERT INTO candidates (
    id,
    name,
    email,
    entry_sheet_data,
    interview_status,
    final_decision
) VALUES (
    'c0000000-0000-0000-0000-000000000001',
    '山田 太郎',
    'yamada.taro@example.com',
    JSON_OBJECT(
        'pr', '大学時代はプログラミングサークルの部長として、チームでのアプリ開発を牽引しました。',
        'gakuchika', '学内の課題提出管理Webアプリを開発し、アクティブユーザー1,000人を達成しました。',
        'motivation', '御社の対話型AIサービス開発に強く共感し、バックエンドエンジニアとして貢献したいと考えています。'
    ),
    'ready',
    'unreviewed'
) ON DUPLICATE KEY UPDATE name=VALUES(name);