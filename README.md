# Mati

**コンテンツ保護・メタタグ管理・SEO設定を簡単に制御できるWordPressプラグイン。**

[![License: GPLv3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/Version-1.7.1-green.svg)](https://github.com/villyoshioka/mati/releases)

> **注意**: **このプラグインについて、コードは公開していますが、サポートは行っていません。**

---

## これは何？

WordPressサイトを守るための軽量プラグインです。ギリシャの魔除け「Mati（マティ）」をコンセプトに、完全な防御ではなく軽度の抑止力を提供する「お守り」として機能します。

### 主な機能

- 不要な情報の非表示（WordPressバージョン、oEmbed、RSD、wlwmanifest、shortlink、pingback）
- コンテンツ保護（右クリック禁止、デベロッパーツール系キー無効化、テキスト選択禁止、画像ドラッグ禁止、印刷禁止、検索エンジンキャッシュ拒否、AI学習防止メタタグ）
- SEO（Google Search Console、Bing Webmaster Tools認証メタタグ、JSON-LD構造化データ）
- SNS本人認証（Misskey・Mastodon・Bluesky）

### Carry Podとの連携

このプラグインは、静的化プラグイン「[Carry Pod](https://github.com/villyoshioka/CarryPod)」と併用することで効果を最大化します。Matiで設定した内容は、Carry Podによる静的化時に自動的に含まれます。

---

## インストール

1. [Releases](https://github.com/villyoshioka/mati/releases) から ZIP ファイルをダウンロード
2. WordPress の管理画面で「プラグイン」→「新規追加」→「プラグインのアップロード」
3. ダウンロードした ZIP ファイルを選択してインストール
4. 「有効化」をクリック

---

## 使い方

プラグインを有効化すると、WordPress 管理画面に「Mati」メニューが追加されます。

1. **不要な情報の非表示**: WordPressバージョン情報など、セキュリティリスクとなる情報を非表示にできます
2. **コンテンツ保護**: 右クリック禁止やテキスト選択禁止など、コンテンツのコピーを抑止する機能を有効化できます
3. **SEO**: Google Search Console、Bing Webmaster Toolsの認証メタタグを簡単に設定できます
4. **SNS本人認証**: Misskey/Mastodonの本人確認マーク（緑のチェック✓）の取得や、Blueskyのドメイン認証に対応しています
5. **iframe埋め込み許可ドメイン**: 外部サイトからのiframe埋め込みを許可するドメインを設定できます

### 一括設定と個別設定の切り替え

「不要な情報の非表示をすべて有効にする」や「コンテンツ保護をすべて有効にする」といった親チェックボックスを使うと、配下の設定項目を一括でON/OFFできます。

- 親チェックボックスをONにすると、配下の全項目が一括でONになります
- その後、個別の項目を変更すると、親チェックボックスが自動的にOFFになり、個別設定モードに切り替わります
- 個別設定モードでは、必要な項目だけを選んで有効化できます

### Misskey/Mastodonの本人確認の設定方法

1. 「SNS本人認証設定」を展開
2. MisskeyまたはMastodonのプロフィールURLを入力（例: https://misskey.io/@username）
3. 最大5個まで追加可能
4. 設定を保存後、Fediverse側のプロフィール編集でこのサイトのURLを追加
5. 自動的に本人確認マーク（緑のチェック✓）が表示されます

### Blueskyドメイン認証の設定方法

1. 「SNS本人認証設定」を展開
2. Blueskyプロフィール URL にドメイン変更前のプロフィールURLを入力（例: https://bsky.app/profile/username.bsky.social）
3. 設定を保存
4. 静的サイトの場合は[CarryPod](https://github.com/villyoshioka/CarryPod)で静的化して公開
5. Blueskyアプリで 設定 → アカウント → ハンドルを変更 → 「自分のドメインを持っています」を選択
6. 「DNSパネルがない場合」に切り替え → サイトのドメインを入力 → 「テキストファイルを確認」で認証

---

## 注意事項

- コンテンツ保護機能は完全な防御ではなく、抑止力として機能します
- 重要なコンテンツには、より強力な保護手段の併用を推奨します
- CarryPodで静的化している場合、Blueskyドメイン認証およびiframe埋め込み許可ドメイン設定を利用するにはCarryPod v2.8.1以降が必要です

---

## ライセンス

このプラグインは GPLv3 ライセンスで公開されています。

---

## プライバシーについて

このプラグインはWordPressサイトのメタタグ管理とコンテンツ保護機能のみを提供します。

- ユーザーデータの収集・解析なし
- トラッキング機能なし

---

## 開発について

このプラグインは、Claude（Anthropic 社の AI）を用いて実装されました。設計・仕様策定・品質管理は開発者が行っています。

詳細は [AI 利用ポリシー](AI_POLICY.md) をご覧ください。

**開発**: Vill Yoshioka ([@villyoshioka](https://github.com/villyoshioka))
