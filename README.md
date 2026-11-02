# muse-email-connector

さくらインターネットのメールボックスを、Muse のカスタムコネクターとして読み書きするための一式です。

## 構成

- `bin/mail_muse.py` — クライアント側ヘルパー。`check` / `list` / `read` / `send` の4コマンド。
- `worker/muse-mail-bridge.js` — Cloudflare Workers 版の中間アダプタ (ブリッジ)。Workers の TCP ソケットで IMAP/SMTP を話します。デプロイ専用リポジトリ [museconnectors/muse-mail-bridge](https://github.com/museconnectors/muse-mail-bridge) から、Git 連携でそのままデプロイもできます (ダッシュボードのコードエディタで貼り替える必要はありません)。
- `bridge/muse-mail-bridge.php` — Web スペースのある契約向けの PHP 版ブリッジ (Workers 版と同じ役割)。
- `bridge/mail-bridge-config.sample.php` — PHP 版の設定サンプル。
- `CONNECTOR.md` — セットアップと運用の詳細ドキュメント。

## なぜブリッジが要るのか

Muse の資格情報保管 (サロゲートの差し替え) は HTTP リクエストの中でしか効かず、IMAP/SMTP のログインには差し替えが入りません (実地検証済み: サロゲートをそのまま IMAP パスワードに使うと Login failed になります)。

そこで、HTTPS で受ける中間アダプタを別に置く構成にしています。

- ブリッジモード (デフォルト): ヘルパー → HTTPS + トークン (差し替えが効く) → ブリッジ → IMAP/SMTP。メールボックスのパスワードはブリッジ側のシークレットにだけ置き、Muse の保管庫に入るのはブリッジ用トークンだけです。
- 直接モード (`--direct`): ヘルパーが IMAP/SMTP を直接話します。パスワードはタスクごとに標準入力で渡す一時利用で、保存はしません。

## セットアップの流れ

1. さくらのコントロールパネルでメールボックスを作成する。
2. Muse の Settings → Permissions → Direct network protocols で `email_mailbox` / `email_smtp` を Ask にする (直接モードで必要)。
3. Worker をデプロイし、シークレット `BRIDGE_TOKEN` (自分で決めた長いランダム文字列) と `MAIL_PASS` (メールボックスのパスワード) を設定する。
4. コネクション `custom.sakura-mail` を登録し、保管庫には `BRIDGE_TOKEN` と同じ値を入れる。登録ホストは Worker のホストにする。
5. `mail_muse.py check` で疎通を確認する。段階的には、ブリッジの `ping` → `check` → 自分宛て `send` を `list` で確認、の順がおすすめです。

詳細は `CONNECTOR.md` を参照してください。

## 注意

- このリポジトリ内のメールアドレス・サーバー名はすべてプレースホルダ (`you@your-domain.example`、`your-initial-domain.sakura.ne.jp` など) に置換してあります。自分の環境の値に読み替えてください。パスワードやトークンの実値は含まれていません。
- さくらの「国外IPアドレスフィルター」が有効だと、国外IPからの SMTP 送信が認証成功後に `550 5.7.1 Command rejected` で拒否されます。直接モード (接続元が国外の場合) や Workers 経由の送信では無効化が必要です。受信 (IMAP) は影響を受けません。
- さくらのログイン ID はメールアドレス全体です (@ の前だけ入れるのは典型的な失敗)。
- さくらはメール専用プランだと Web スペースが無いため、PHP 版ブリッジは置けません。その場合は Workers 版を使ってください。
