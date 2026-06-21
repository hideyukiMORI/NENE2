# FT Registry

FT 番号とディレクトリ名の対応表。  
新規 FT を追加する前に `tools/new-ft.sh` がこのファイルを参照して重複を検出する。

## 使い方

```bash
# 新規 FT のスキャフォールド（FT番号とディレクトリ名を指定）
tools/new-ft.sh 171 newfeaturelog
```

スクリプトが以下を行う:
1. ディレクトリ名の重複チェック
2. `../NENE2-FT/<dirname>/` 作成（src/ tests/ database/ 含む）
3. 直近の FT プロジェクトから vendor をコピー + dump-autoload
4. このファイルへの追記を促すメッセージを表示

## FT001〜FT095（名前のみ）

> FT096 以前はレジストリ導入前。ディレクトリ名は以下の通り使用済み。

```
ablog approvallog apikeylog auditlog authlog batchlog bookinglog bookmarklog
budgetlog bulklog bulkupdatelog cachelog cartlog circuitlog collectionlog
commentlog contactlog contentlog contentvlog couponlog cqrslog creditslog
csrflog cursorlog deadletterlog distlocklog doclog draftlog emojilog etaglog
eventsourcelog eventstore expenselog exportlog featureflaglog feedlog filelog
flaglog flowlog followlog ftslog grouplog habitlog hmaclog i18nlog
idempotencylog importlog injectionlog inventorylog invitelog jwtlog
leaderboardlog linklog locklog lockoutlog magiclog masslog messagelog
moneylog nestedlog noteslog notificationlog oauthlog optimisticlog optlocklog
orderlog otplog pagelog paymentlog pinlog planlog pointlog polllog preflog
pricelog profilelog projtrack pwdlog queuelog quotalog ranklog ratelimitlog
ratelog ratinglog rbaclog reactionlog refreshlog reservelog resetlog reviewlog
salelog schedulelog scorelog searchlog sessionlog shiftlog signedlog
softdelete softdeletelog softlog statemachinelog statslog subscriptionlog
tagfilterlog taglog tenantlog threadlog throttlelog timelog tokenlog totplog
treelog unicodelog uploadlog votelog waitlistlog watchlog webhookdeliverylog
webhooklog wishlistlog workflowlog
```

## FT096〜FT214（番号 ↔ ディレクトリ対応）

| FT | ディレクトリ | テーマ |
|---|---|---|
| FT096 | contentlog | コンテントネゴシエーション |
| FT097 | injectionlog | SQL インジェクション防御 |
| FT098 | uploadlog | ファイルアップロード |
| FT099 | csrflog | CSRF 的パターン・冪等性 |
| FT100 | pagelog | OFFSET vs カーソルページネーション |
| FT101 | nestedlog | ネスト JSON バリデーション |
| FT102 | txlog | DB トランザクション境界 |
| FT103 | masslog | マスアサインメント防御 |
| FT104 | hmaclog | Webhook シグネチャ検証 |
| FT105 | optlocklog | 楽観的ロック |
| FT106 | etaglog | ETag・条件付きリクエスト |
| FT107 | throttlelog | レートリミット |
| FT108 | softdeletelog | ソフトデリート |
| FT109 | pwdlog | パスワードハッシュ |
| FT110 | jwtlog | JWT 認証 |
| FT111 | rbaclog | RBAC |
| FT112 | tenantlog | マルチテナント隔離 |
| FT113 | refreshlog | JWT Refresh Token Rotation |
| FT114 | auditlog | 監査ログ（Audit Trail） |
| FT115 | versionlog | API バージョニング |
| FT116 | queuelog | バックグラウンドジョブキュー |
| FT117 | apikeylog | API キー管理 |
| FT118 | signedlog | 署名付き URL |
| FT119 | circuitlog | サーキットブレーカー |
| FT120 | webhookdeliverylog | アウトバウンド Webhook 配信 |
| FT121 | featureflaglog | フィーチャーフラグ |
| FT122 | distlocklog | 分散ロック |
| FT123 | exportlog | 個人データエクスポート |
| FT124 | invitelog | ユーザー招待システム |
| FT125 | taglog | タグシステム（M:N） |
| FT126 | resetlog | パスワードリセット |
| FT127 | commentlog | スレッドコメント |
| FT128 | lockoutlog | アカウントロックアウト |
| FT129 | eventsourcelog | イベントソーシング（基本） |
| FT130 | notificationlog | 通知インボックス |
| FT131 | votelog | コメント投票 |
| FT132 | profilelog | プロフィール管理 |
| FT133 | bookmarklog | ブックマーク |
| FT134 | followlog | ユーザーフォロー |
| FT135 | messagelog | ダイレクトメッセージ |
| FT136 | tokenlog | アクセストークン管理 |
| FT137 | planlog | サブスクリプション管理 |
| FT138 | grouplog | グループメンバーシップ |
| FT139 | orderlog | ゲスト注文 |
| FT140 | salelog | フラッシュセール |
| FT141 | ranklog | リーダーボード |
| FT142 | draftlog | コンテンツ下書き |
| FT143 | emojilog | 絵文字リアクション |
| FT144 | magiclog | パスワードレス認証（Magic Link） |
| FT145 | preflog | ユーザー設定管理 |
| FT146 | pinlog | コンテンツピン留め |
| FT147 | reportlog | コンテンツ通報・モデレーション |
| FT148 | otplog | OTP 認証システム |
| FT149 | collectionlog | コンテンツコレクション |
| FT150 | couponlog | クーポン・プロモコード管理 |
| FT151 | wishlistlog | ウィッシュリスト管理 |
| FT152 | pointlog | ポイント・ロイヤルティシステム |
| FT153 | feedlog | アクティビティフィード |
| FT154 | reviewlog | プロダクトレビュー・評価システム |
| FT155 | cartlog | ショッピングカート |
| FT156 | filelog | ファイルメタデータ管理・共有 |
| FT157 | searchlog | 全文検索・オートコンプリート |
| FT158 | importlog | CSV バルクインポート |
| FT159 | totplog | TOTP 二要素認証 |
| FT160 | oauthlog | OAuth2 Social Login |
| FT161 | cachelog | Application Caching |
| FT162 | contentvlog | Content Versioning |
| FT163 | paymentlog | Payment Webhook 受信 |
| FT164 | geoloclog | Geolocation |
| FT165 | ablog | A/B Testing |
| FT166 | stepflowlog | Multi-step Workflow ※workflowlog(FT081)と衝突のため変更 |
| FT167 | inboundlog | Inbound Webhook Receiver |
| FT168 | agglog | Admin Report Aggregation ※reportlog(FT147)と衝突のため変更 |
| FT169 | masklog | Data Masking |
| FT170 | deduplog | Request Deduplication |
| FT171 | hierarchylog | Hierarchical Data（自己参照FK＋マテリアライズドパス） |
| FT172 | pubschedulelog | Content Scheduling（時間指定公開・ライフサイクル状態機械） |
| FT173 | relatedlog | Content Relations（型付きM:N自己参照リンク） |
| FT174 | sluglog | Slug Management（URL スラグ生成・衝突解決・履歴リダイレクト） |
| FT175 | meterlog | API Usage Metering（per-user 日次クォータ・usage_events 追記・ゲートチェック） |
| FT176 | grantlog | Delegated Access Grants（multi-party 委譲・time-limited・revocable・state machine 攻撃試験） |
| FT177 | limitlog | Pagination Boundary Attack（ctype_digit・overflow guard・ReDoS safe・VULN-A〜L） |
| FT178 | patchlog | JSON Merge Patch & ETag Conflict Detection — partial update, immutable field protection, V.php first real usage |
| FT179 | isolationlog | Tenant Isolation & Cross-Tenant IDOR Prevention — multi-tenant resource scoping, X-Tenant-Id validation, V.php reuse |
| FT180 | sortlog | SQL ORDER BY Injection Prevention — allowlist-based sort/filter, VULN-A~L + ATK-01~12 double special FT |
| FT181 | reminderlog | ISO 8601 Datetime Validation & Timezone-Aware API — V::isoDatetime + V::futureDatetime real-world usage |
| FT183 | shortlog | URL Shortener API & SSRF Prevention — 脆弱性診断 VULN-A~L |
| FT184 | onetimelog | One-Time Secret API & ATK-01~12 Cracker Attack Test |
| FT185 | statuslog | Service Status Page API — component health, incident lifecycle, admin key auth |
| FT187 | encryptlog | Encrypted Field Storage — AES-256-GCM per-field encryption + blind index search |
| FT188 | verifylog | Numeric Verification Code — 6-digit code with brute-force protection, ATK-01~12 |
| FT189 | consentlog | Privacy Consent Management — GDPR-style consent tracking with VULN-A~L security audit |
| FT190 | announcelog | System Announcement Management — time-based activation, admin key auth, per-user dismissal |
| FT192 | pinverifylog | PIN Verification with Lockout — HMAC-SHA256 PIN, brute force lockout, admin unlock, VULN-A~L & ATK-01~12 |
| FT193 | reservationlog | Resource Reservation / Time Slot Booking — overlap prevention, IDOR protection, ISO 8601 range comparison |
| FT194 | assetlog | Asset Check-out / Check-in — exclusive hold, ownership check, append-only history |
| FT195 | vaultlog | Personal Secret Vault — per-user key-value store with HMAC integrity and IDOR prevention |
| FT196 | ticketlog | Event Ticket Booking — capacity management, per-user purchase, ATK-01~12 |
| FT197 | templatelog | Document Template Engine — variable substitution, version tracking |
| FT198 | walletlog | Multi-Currency Wallet — balance tracking, deposit/withdraw, transfer |
| FT202 | notelog | Note management API with tag-based search |
| FT205 | feedbacklog | Feedback collection API (score + comment + stats) |
| FT212 | productlog | Product Catalog API (ATK-01~12 cracker attack test) |
| FT214 | notiflog | Notification Queue API (send, list, read, delete) |

## FT215 以降（howto-only 運用）

新規 FT プロジェクトディレクトリの作成は **FT214（notiflog）が最後**。以降の運用は次のとおりで、本台帳の番号 ↔ ディレクトリ対応表もここで打ち止めとする。

- **FT215〜FT221**: 既存ディレクトリ名を再利用（例: FT209→`taglog`(初出 FT125)、FT215→`orderlog`(初出 FT139)）。再利用名は初出エントリで衝突検出されるため、本表に新規行は追加しない。
- **FT222 以降**: howto-only 運用。`../NENE2-FT/` に新しいプロジェクトディレクトリを作らず、howto で新パターンを文書化する（実装 example は別リポジトリ NENE2-examples 側に寄せる方針）。

> **重複検出は 2 段**: `tools/new-ft.sh` は ① 本台帳の `grep` ② `../NENE2-FT/<name>` の実在チェック で名前衝突を防ぐ。新規ディレクトリを作る運用に戻る場合は、この表へ追記して台帳側の検出も維持すること。
