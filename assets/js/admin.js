/**
 * Mati 管理画面JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {

		// ========================================
		// 未保存の変更を追跡
		// ========================================

		let hasUnsavedChanges = false;

		// フォームの変更を監視
		$('#mati-settings-form').on('change', 'input, textarea, select', function() {
			hasUnsavedChanges = true;
		});

		// ページ離脱時の確認
		$(window).on('beforeunload', function(e) {
			if (hasUnsavedChanges) {
				const message = '変更が保存されていません。このページを離れますか？';
				e.returnValue = message;
				return message;
			}
		});

		// ========================================
		// 確認ダイアログ用の関数
		// ========================================

		function showConfirm(message, onConfirm, onCancel) {
			// 既存の確認ダイアログを削除
			$('.mati-confirm-dialog').remove();

			// ダイアログHTMLを作成
			const $dialog = $('<div class="mati-confirm-dialog">' +
				'<div class="mati-confirm-overlay"></div>' +
				'<div class="mati-confirm-box">' +
				'<h3>確認</h3>' +
				'<p>' + message + '</p>' +
				'<div class="mati-confirm-buttons">' +
				'<button class="button button-primary mati-confirm-yes">はい</button>' +
				'<button class="button mati-confirm-no">いいえ</button>' +
				'</div>' +
				'</div>' +
				'</div>');

			// ダイアログを追加
			$('body').append($dialog);

			// はいボタンのイベント
			$dialog.find('.mati-confirm-yes').on('click', function() {
				$dialog.remove();
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
			});

			// いいえボタンのイベント
			$dialog.find('.mati-confirm-no, .mati-confirm-overlay').on('click', function() {
				$dialog.remove();
				if (typeof onCancel === 'function') {
					onCancel();
				}
			});
		}

		// ========================================
		// アコーディオン機能
		// ========================================

		$('.mati-accordion-header').on('click', function() {
			const $header = $(this);
			const $content = $header.next('.mati-accordion-content');
			const isExpanded = $header.attr('aria-expanded') === 'true';

			if (isExpanded) {
				// 閉じる
				$header.attr('aria-expanded', 'false');
				$content.attr('aria-hidden', 'true').slideUp(200);
			} else {
				// 開く
				$header.attr('aria-expanded', 'true');
				$content.attr('aria-hidden', 'false').slideDown(200);
			}
		});

		// ========================================
		// 親子チェックボックスの挙動
		// ========================================

		// 親チェックボックスがONになったとき → 全ての子をONにする
		$('#mati-meta-removal-enabled, #mati-content-protection-enabled').on('change', function() {
			const $parent = $(this);
			const parentId = $parent.attr('id');
			const isChecked = $parent.prop('checked');

			if (isChecked) {
				// 親がONになった → 全ての子をONにする
				$('.mati-child-checkbox[data-parent="' + parentId + '"]').prop('checked', true);
			}
		});

		// 子チェックボックスが変更されたとき → 親を自動でOFFにする
		$('.mati-child-checkbox').on('change', function() {
			const $child = $(this);
			const parentId = $child.data('parent');
			const $parent = $('#' + parentId);

			// 親がONの状態で子を変更した場合、親を自動でOFFにする
			if ($parent.prop('checked')) {
				$parent.prop('checked', false);
			}
		});

		// ========================================
		// フォーム送信（保存）
		// ========================================

		$('#mati-settings-form').on('submit', function(e) {
			e.preventDefault();

			const $form = $(this);
			const $saveButton = $('#mati-save-button');
			const $spinner = $('.mati-form-actions .spinner');
			const $message = $('#mati-message');

			// ボタンを無効化、スピナー表示
			$saveButton.prop('disabled', true);
			$spinner.addClass('is-active');
			$message.hide();

			// フォームデータを収集
			const formData = {};
			$form.find('input[type="checkbox"]').each(function() {
				const $checkbox = $(this);
				formData[$checkbox.attr('name')] = $checkbox.prop('checked') ? '1' : '';
			});
			$form.find('input[type="text"], input[type="url"]').each(function() {
				const $input = $(this);
				const name = $input.attr('name');

				// 配列形式（name[]）の場合
				if (name && name.endsWith('[]')) {
					// []を削除したキー名を使用
					const arrayKey = name.replace('[]', '');
					if (!formData[arrayKey]) {
						formData[arrayKey] = [];
					}
					formData[arrayKey].push($input.val());
				} else {
					// 通常のフィールド
					formData[name] = $input.val();
				}
			});

			// Ajax送信
			$.ajax({
				url: matiData.ajaxurl,
				type: 'POST',
				data: {
					action: 'mati_save_settings',
					nonce: matiData.nonce,
					settings: formData
				},
				success: function(response) {
					if (response.success) {
						// 保存成功時は未保存フラグをクリア
						hasUnsavedChanges = false;

						$message
							.removeClass('notice-error')
							.addClass('notice notice-success')
							.html('<p>' + response.data.message + '</p>')
							.slideDown();

						// CarryPodと同様に、保存後にページをリロード
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$message
							.removeClass('notice-success')
							.addClass('notice notice-error')
							.html('<p>' + response.data.message + '</p>')
							.slideDown();

						// 3秒後にメッセージを非表示
						setTimeout(function() {
							$message.slideUp();
						}, 3000);
					}
				},
				error: function() {
					$message
						.removeClass('notice-success')
						.addClass('notice notice-error')
						.html('<p>通信エラーが発生しました。</p>')
						.slideDown();
				},
				complete: function() {
					// ボタンを有効化、スピナー非表示
					$saveButton.prop('disabled', false);
					$spinner.removeClass('is-active');
				}
			});
		});

		// ========================================
		// リセットボタン
		// ========================================

		$('#mati-reset-button').on('click', function(e) {
			e.preventDefault();

			const $resetButton = $(this);
			const $spinner = $('.mati-form-actions .spinner');
			const $message = $('#mati-message');

			showConfirm('本当にリセットしますか？\nサイトへの変更も全て元に戻ります。', function() {
				// ボタンを無効化、スピナー表示
				$resetButton.prop('disabled', true);
				$spinner.addClass('is-active');
				$message.hide();

				// Ajax送信
				$.ajax({
					url: matiData.ajaxurl,
					type: 'POST',
					data: {
						action: 'mati_reset_settings',
						nonce: matiData.nonce
					},
					success: function(response) {
						if (response.success) {
							// リセット成功時は未保存フラグをクリア（リロード前に）
							hasUnsavedChanges = false;

							$message
								.removeClass('notice-error')
								.addClass('notice notice-success')
								.html('<p>' + response.data.message + '</p>')
								.slideDown();

							// ページをリロード（設定値を反映するため）
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$message
								.removeClass('notice-success')
								.addClass('notice notice-error')
								.html('<p>' + response.data.message + '</p>')
								.slideDown();
						}
					},
					error: function() {
						$message
							.removeClass('notice-success')
							.addClass('notice notice-error')
							.html('<p>通信エラーが発生しました。</p>')
							.slideDown();
					},
					complete: function() {
						// ボタンを有効化、スピナー非表示
						$resetButton.prop('disabled', false);
						$spinner.removeClass('is-active');
					}
				});
			}, function() {
				// キャンセル時は何もしない
			});
		});

		// ========================================
		// 設定のエクスポート
		// ========================================

		$('#mati-export-settings').on('click', function() {
			$.ajax({
				url: matiData.ajaxurl,
				type: 'POST',
				data: {
					action: 'mati_export_settings',
					nonce: matiData.nonce
				},
				success: function(response) {
					if (response.success) {
						const blob = new Blob([response.data.data], { type: 'application/json' });
						const url = URL.createObjectURL(blob);
						const a = document.createElement('a');
						a.href = url;
						a.download = 'mati-settings.json';
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
						URL.revokeObjectURL(url);
					} else {
						alert(response.data.message);
					}
				},
				error: function() {
					alert('エラーが発生しました。');
				}
			});
		});

		// ========================================
		// 設定のインポート
		// ========================================

		$('#mati-import-settings').on('click', function() {
			$('#mati-import-file').click();
		});

		$('#mati-import-file').on('change', function(e) {
			const file = e.target.files[0];
			if (!file) {
				return;
			}

			const reader = new FileReader();
			reader.onload = function(event) {
				const data = event.target.result;

				if (!confirm('設定をインポートしますか？現在の設定は上書きされます。')) {
					return;
				}

				$.ajax({
					url: matiData.ajaxurl,
					type: 'POST',
					data: {
						action: 'mati_import_settings',
						nonce: matiData.nonce,
						data: data
					},
					success: function(response) {
						if (response.success) {
							// インポート成功時は未保存フラグをクリア（リロード前に）
							hasUnsavedChanges = false;

							alert(response.data.message);
							location.reload();
						} else {
							alert(response.data.message);
						}
					},
					error: function() {
						alert('エラーが発生しました。');
					}
				});
			};
			reader.readAsText(file);

			// ファイル選択をリセット（同じファイルを再選択可能にする）
			$(this).val('');
		});

		// ========================================
		// Fediverse URL の動的追加・削除
		// ========================================

		// URL を追加
		$('#mati-add-fediverse-url').on('click', function() {
			const $container = $('#mati-fediverse-urls-container');
			const currentCount = $container.find('.mati-fediverse-url-row').length;

			// 最大5個まで
			if (currentCount >= 5) {
				alert('URLは最大5個までです。');
				return;
			}

			// 新しい行を追加
			const $newRow = $('<div class="mati-fediverse-url-row">' +
				'<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="" placeholder="例: https://misskey.io/@username">' +
				'<button type="button" class="mati-remove-url">削除</button>' +
				'</div>');

			$container.append($newRow);

			// 削除ボタンの表示を更新
			updateRemoveButtons();
		});

		// URL を削除
		$(document).on('click', '.mati-remove-url', function() {
			$(this).closest('.mati-fediverse-url-row').remove();
			updateRemoveButtons();
		});

		// 削除ボタンの表示を更新（1個の場合は削除ボタンを非表示）
		function updateRemoveButtons() {
			const $container = $('#mati-fediverse-urls-container');
			const $rows = $container.find('.mati-fediverse-url-row');
			const count = $rows.length;

			if (count === 1) {
				$rows.find('.mati-remove-url').hide();
			} else {
				$rows.find('.mati-remove-url').show();
			}

			// 5個に達したら追加ボタンを無効化
			const $addButton = $('#mati-add-fediverse-url');
			if (count >= 5) {
				$addButton.prop('disabled', true);
			} else {
				$addButton.prop('disabled', false);
			}
		}

		// 初期表示時に削除ボタンの表示を更新
		updateRemoveButtons();

		// ========================================
		// ツールチップ機能
		// ========================================

		// ツールチップの初期化
		function initTooltips() {
			// 既存のツールチップイベントをクリア（重複防止）
			$(document).off('click.matiTooltip keydown.matiTooltip');
			$(document).off('click.matiTooltipOutside');

			// トリガークリック時の処理
			$(document).on('click.matiTooltip', '.mati-tooltip-trigger', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $trigger = $(this);
				const $wrapper = $trigger.closest('.mati-tooltip-wrapper');
				const $tooltip = $wrapper.find('.mati-tooltip-content');
				const isActive = $trigger.hasClass('active');

				// 他のツールチップを全て閉じる
				$('.mati-tooltip-trigger').removeClass('active');
				$('.mati-tooltip-content').removeClass('show');

				// クリックされたツールチップをトグル
				if (!isActive) {
					$trigger.addClass('active');
					$tooltip.addClass('show');
					$trigger.attr('aria-expanded', 'true');
				} else {
					$trigger.attr('aria-expanded', 'false');
				}
			});

			// キーボード操作
			$(document).on('keydown.matiTooltip', '.mati-tooltip-trigger', function(e) {
				const $trigger = $(this);
				const $wrapper = $trigger.closest('.mati-tooltip-wrapper');
				const $tooltip = $wrapper.find('.mati-tooltip-content');

				// Enter or Space: トグル
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					$trigger.trigger('click');
				}

				// Escape: 閉じる
				if (e.key === 'Escape') {
					e.preventDefault();
					$trigger.removeClass('active');
					$tooltip.removeClass('show');
					$trigger.attr('aria-expanded', 'false');
				}
			});

			// 外側クリックで閉じる
			$(document).on('click.matiTooltipOutside', function(e) {
				if (!$(e.target).closest('.mati-tooltip-wrapper').length) {
					$('.mati-tooltip-trigger').removeClass('active');
					$('.mati-tooltip-content').removeClass('show');
					$('.mati-tooltip-trigger').attr('aria-expanded', 'false');
				}
			});

			// フォーカスアウト時の処理
			$('.mati-tooltip-trigger').on('blur', function() {
				const $trigger = $(this);
				// 短い遅延を設けて、他の要素へのフォーカス移動を確認
				setTimeout(function() {
					if (!$trigger.is(':focus')) {
						$trigger.removeClass('active');
						$trigger.closest('.mati-tooltip-wrapper').find('.mati-tooltip-content').removeClass('show');
						$trigger.attr('aria-expanded', 'false');
					}
				}, 100);
			});
		}

		// ツールチップ初期化を実行
		initTooltips();

	});

})(jQuery);
