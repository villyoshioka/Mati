/**
 * Mati 管理画面JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {

		// ========================================
		// prefers-reduced-motion 対応
		// ========================================

		var motionDuration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 120;
		var motionDurationLong = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200;

		// ========================================
		// 未保存の変更を追跡
		// ========================================

		let hasUnsavedChanges = false;

		$('#mati-settings-form').on('change', 'input, textarea, select', function() {
			hasUnsavedChanges = true;
		});

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
			$('.nau-confirm-dialog').remove();

			const $dialog = $('<div class="nau-confirm-dialog">' +
				'<div class="nau-confirm-overlay"></div>' +
				'<div class="nau-confirm-box">' +
				'<h3>確認</h3>' +
				'<p>' + message + '</p>' +
				'<div class="nau-confirm-buttons">' +
				'<button class="button button-primary nau-confirm-yes">はい</button>' +
				'<button class="button nau-confirm-no">いいえ</button>' +
				'</div>' +
				'</div>' +
				'</div>');

			$('body').append($dialog);

			$dialog.find('.nau-confirm-yes').on('click', function() {
				$dialog.remove();
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
			});

			$dialog.find('.nau-confirm-no, .nau-confirm-overlay').on('click', function() {
				$dialog.remove();
				if (typeof onCancel === 'function') {
					onCancel();
				}
			});
		}

		// ========================================
		// アコーディオン状態管理（LocalStorage）
		// ========================================

		/**
		 * LocalStorageから特定のアコーディオンの状態を取得
		 * @param {string} sectionId - セクションID
		 * @return {boolean|null} - 保存された状態、またはnull
		 */
		function getAccordionState(sectionId) {
			try {
				const states = localStorage.getItem('mati_accordion_states');
				if (!states) return null;

				const parsed = JSON.parse(states);
				return parsed[sectionId] !== undefined ? parsed[sectionId] : null;
			} catch (e) {
				console.error('LocalStorage読み込みエラー:', e);
				return null;
			}
		}

		/**
		 * LocalStorageに特定のアコーディオンの状態を保存
		 * @param {string} sectionId - セクションID
		 * @param {boolean} isExpanded - 開いているか
		 */
		function saveAccordionState(sectionId, isExpanded) {
			try {
				let states = {};
				const existing = localStorage.getItem('mati_accordion_states');

				if (existing) {
					states = JSON.parse(existing);
				}

				states[sectionId] = isExpanded;
				localStorage.setItem('mati_accordion_states', JSON.stringify(states));
			} catch (e) {
				console.error('LocalStorage保存エラー:', e);
			}
		}

		/**
		 * すべてのアコーディオンの現在の状態をLocalStorageに一括保存
		 */
		function saveAllAccordionStates() {
			try {
				const states = {};
				$('.nau-accordion-header').each(function() {
					const sectionId = $(this).closest('.nau-accordion-section').data('section');
					const isExpanded = $(this).attr('aria-expanded') === 'true';
					states[sectionId] = isExpanded;
				});
				localStorage.setItem('mati_accordion_states', JSON.stringify(states));
			} catch (e) {
				console.error('LocalStorage一括保存エラー:', e);
			}
		}

		/**
		 * アコーディオンの状態を設定
		 * @param {boolean} noTransition - trueの場合、トランジションなしで即座に状態を変更
		 */
		function setAccordionState(header, content, isExpanded, noTransition) {
			const $header = $(header);
			const $content = $(content);

			$header.attr('aria-expanded', isExpanded);
			$content.attr('aria-hidden', !isExpanded);

			if (noTransition) {
				// 初期表示時: トランジションなしで即座に状態を設定
				if (isExpanded) {
					$content.show();
				} else {
					$content.hide();
				}
			} else {
				// ユーザー操作時: jQueryのslideアニメーション
				if (isExpanded) {
					$content.slideDown(motionDuration);
				} else {
					$content.slideUp(motionDuration);
				}
			}
		}

		/**
		 * デフォルトの開閉状態を取得
		 */
		function getDefaultState(sectionId) {
			const defaultExpanded = ['content-protection'];
			return defaultExpanded.includes(sectionId);
		}

		/**
		 * アコーディオン機能の初期化
		 */
		function initAccordions() {
			const accordions = document.querySelectorAll('.nau-accordion-section');

			accordions.forEach(function(accordion) {
				const header = accordion.querySelector('.nau-accordion-header');
				const content = accordion.querySelector('.nau-accordion-content');
				const sectionId = accordion.dataset.section;

				if (!header || !content) return;

				// LocalStorageから状態を取得
				const savedState = getAccordionState(sectionId);
				const isExpanded = savedState !== null ? savedState : getDefaultState(sectionId);

				// 初期状態を設定（アニメーションなし）
				content.classList.add('nau-no-transition');
				setAccordionState(header, content, isExpanded, true);

				requestAnimationFrame(function() {
					content.classList.remove('nau-no-transition');
				});

				header.addEventListener('click', function() {
					const currentState = header.getAttribute('aria-expanded') === 'true';
					const newState = !currentState;

					setAccordionState(header, content, newState);
					// LocalStorageへの保存はフォーム保存時のみ行う
				});

				header.addEventListener('keydown', function(e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						header.click();
					}
				});
			});
		}

		// ========================================
		// 親子チェックボックスの挙動
		// ========================================

		$('#mati-meta-removal-enabled, #mati-content-protection-enabled').on('change', function() {
			const $parent = $(this);
			const parentId = $parent.attr('id');
			const isChecked = $parent.prop('checked');

			if (isChecked) {
				$('.mati-child-checkbox[data-parent="' + parentId + '"]').prop('checked', true);
			}
		});

		$('.mati-child-checkbox').on('change', function() {
			const $child = $(this);
			const parentId = $child.data('parent');
			const $parent = $('#' + parentId);

			if ($parent.prop('checked')) {
				$parent.prop('checked', false);
			}
		});

		// ========================================
		// テキスト選択禁止: トグル切替時の処理
		// ON/OFFでチェックの意味が反転するため、チェックをクリアし項目名も切り替える
		// ========================================

		$('#mati-disable-text-selection').on('change', function() {
			$('.mati-text-selection-category').prop('checked', false);
			$('#mati-text-selection-category-label').text(
				$(this).prop('checked') ? '制限から除外するカテゴリー' : '制限するカテゴリー'
			);
		});

		// カテゴリー一覧の折りたたみ（11件目以降の表示/非表示）
		$('.mati-category-more').on('click', function() {
			const $button = $(this);
			const $checklist = $button.closest('.mati-category-checklist');
			const collapsed = $checklist.toggleClass('is-collapsed').hasClass('is-collapsed');
			$button.text(collapsed ? $button.data('more-label') : $button.data('less-label'));
		});

		// ========================================
		// 入力フィールドでのEnterキーによるフォーム送信を防止
		// ========================================

		$('#mati-settings-form').on('keydown', 'input[type="text"], input[type="url"], input[type="number"]', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
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

			$saveButton.prop('disabled', true);
			$spinner.addClass('is-active');
			$message.hide();

			const formData = {};
			$form.find('input[type="checkbox"]').each(function() {
				const $checkbox = $(this);
				const name = $checkbox.attr('name');

				if (name && name.endsWith('[]')) {
					const arrayKey = name.replace('[]', '');
					if (!formData[arrayKey]) {
						formData[arrayKey] = [];
					}
					if ($checkbox.prop('checked')) {
						formData[arrayKey].push($checkbox.val());
					}
				} else {
					formData[name] = $checkbox.prop('checked') ? '1' : '';
				}
			});
			$form.find('input[type="text"], input[type="url"], input[type="hidden"]').each(function() {
				const $input = $(this);
				const name = $input.attr('name');

				if (name && name.endsWith('[]')) {
					const arrayKey = name.replace('[]', '');
					if (!formData[arrayKey]) {
						formData[arrayKey] = [];
					}
					formData[arrayKey].push($input.val());
				} else {
					formData[name] = $input.val();
				}
			});
			$form.find("textarea").each(function() {
				const $textarea = $(this);
				const name = $textarea.attr("name");
				if (name) {
					formData[name] = $textarea.val();
				}
			});

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
						hasUnsavedChanges = false;

						saveAllAccordionStates();

						$message
							.removeClass('notice-error')
							.addClass('notice notice-success')
							.html('<p>' + response.data.message + '</p>')
							.slideDown(motionDurationLong);

						// CarryPodと同様に、保存後にページをリロード
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$message
							.removeClass('notice-success')
							.addClass('notice notice-error')
							.html('<p>' + response.data.message + '</p>')
							.slideDown(motionDurationLong);

						setTimeout(function() {
							$message.slideUp(motionDurationLong);
						}, 3000);
					}
				},
				error: function() {
					$message
						.removeClass('notice-success')
						.addClass('notice notice-error')
						.html('<p>通信エラーが発生しました。</p>')
						.slideDown(motionDurationLong);
				},
				complete: function() {
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
				$resetButton.prop('disabled', true);
				$spinner.addClass('is-active');
				$message.hide();

				$.ajax({
					url: matiData.ajaxurl,
					type: 'POST',
					data: {
						action: 'mati_reset_settings',
						nonce: matiData.nonce
					},
					success: function(response) {
						if (response.success) {
							hasUnsavedChanges = false;

							localStorage.removeItem('mati_accordion_states');

							$message
								.removeClass('notice-error')
								.addClass('notice notice-success')
								.html('<p>' + response.data.message + '</p>')
								.slideDown(motionDurationLong);

							// ページをリロード（設定値を反映するため）
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							$message
								.removeClass('notice-success')
								.addClass('notice notice-error')
								.html('<p>' + response.data.message + '</p>')
								.slideDown(motionDurationLong);
						}
					},
					error: function() {
						$message
							.removeClass('notice-success')
							.addClass('notice notice-error')
							.html('<p>通信エラーが発生しました。</p>')
							.slideDown(motionDurationLong);
					},
					complete: function() {
						$resetButton.prop('disabled', false);
						$spinner.removeClass('is-active');
					}
				});
			}, function() {
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

							// アコーディオンの状態もリセット（新しい設定をインポートするため）
							localStorage.removeItem('mati_accordion_states');

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

		$('#mati-add-fediverse-url').on('click', function() {
			const $container = $('#mati-fediverse-urls-container');
			const currentCount = $container.find('.mati-fediverse-url-row').length;

			if (currentCount >= 5) {
				alert('URLは最大5個までです。');
				return;
			}

			const $newRow = $('<div class="nau-input-row mati-fediverse-url-row">' +
				'<input type="url" name="fediverse_profile_urls[]" class="regular-text" value="" placeholder="プロフィールURL（例: https://misskey.io/@username）">' +
				'<button type="button" class="button button-caution nau-input-row-remove mati-remove-url">削除</button>' +
				'</div>');

			$container.append($newRow);

			updateRemoveButtons();
		});

		$(document).on('click', '.mati-remove-url:not(.mati-clear-bluesky)', function() {
			const $container = $('#mati-fediverse-urls-container');
			if ($container.find('.mati-fediverse-url-row').length <= 1) {
				$(this).closest('.mati-fediverse-url-row').find('input').val('');
			} else {
				$(this).closest('.mati-fediverse-url-row').remove();
			}
			updateRemoveButtons();
		});

		function updateRemoveButtons() {
			const count = $('#mati-fediverse-urls-container').find('.mati-fediverse-url-row').length;
			$('#mati-add-fediverse-url').prop('disabled', count >= 5);
		}

		updateRemoveButtons();

		$(document).on("click", ".mati-clear-bluesky", function() {
			const $row = $(this).closest(".mati-fediverse-url-row");
			const $input = $row.find("input[name='bluesky_profile_url']");
			$input.val("");
			$input.attr("placeholder", "プロフィールURL（例: https://bsky.app/profile/username.bsky.social）");
			$input.after('<input type="hidden" name="bluesky_clear" value="1">');
			$(this).hide();
			hasUnsavedChanges = true;
		});

		// ========================================
		// ツールチップ機能
		// ========================================

		function initTooltips() {
			// 既存のツールチップイベントをクリア（重複防止）
			$(document).off('click.matiTooltip keydown.matiTooltip');
			$(document).off('click.matiTooltipOutside');

			$(document).on('click.matiTooltip', '.nau-tooltip-trigger', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $trigger = $(this);
				const $wrapper = $trigger.closest('.nau-tooltip-wrapper');
				const $tooltip = $wrapper.find('.nau-tooltip-content');
				const isActive = $trigger.hasClass('active');

				$('.nau-tooltip-trigger').removeClass('active');
				$('.nau-tooltip-wrapper').removeClass('show');

				if (!isActive) {
					$trigger.addClass('active');
					$wrapper.addClass('show');
					$trigger.attr('aria-expanded', 'true');
				} else {
					$trigger.attr('aria-expanded', 'false');
				}
			});

			$(document).on('keydown.matiTooltip', '.nau-tooltip-trigger', function(e) {
				const $trigger = $(this);
				const $wrapper = $trigger.closest('.nau-tooltip-wrapper');
				const $tooltip = $wrapper.find('.nau-tooltip-content');

				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					$trigger.trigger('click');
				}

				if (e.key === 'Escape') {
					e.preventDefault();
					$trigger.removeClass('active');
					$wrapper.removeClass('show');
					$trigger.attr('aria-expanded', 'false');
				}
			});

			$(document).on('click.matiTooltipOutside', function(e) {
				if (!$(e.target).closest('.nau-tooltip-wrapper').length) {
					$('.nau-tooltip-trigger').removeClass('active');
					$('.nau-tooltip-wrapper').removeClass('show');
					$('.nau-tooltip-trigger').attr('aria-expanded', 'false');
				}
			});

			$('.nau-tooltip-trigger').on('blur', function() {
				const $trigger = $(this);
				// 短い遅延を設けて、他の要素へのフォーカス移動を確認
				setTimeout(function() {
					if (!$trigger.is(':focus')) {
						$trigger.removeClass('active');
						$trigger.closest('.nau-tooltip-wrapper').removeClass('show');
						$trigger.attr('aria-expanded', 'false');
					}
				}, 100);
			});
		}

		initTooltips();

		// ========================================
		// アコーディオンの初期化
		// ========================================

		initAccordions();

		// ========================================
		// CP実行中の操作無効化監視
		// ========================================

		if (matiData.cpIsRunning) {
			var cpPollInterval = setInterval(function() {
				$.ajax({
					url: matiData.ajaxurl,
					type: 'POST',
					data: { action: 'cp_is_running' },
					success: function(response) {
						if (response.success && !response.data.is_running) {
							$('#mati-save-button, #mati-reset-button, #mati-import-settings').prop('disabled', false);
							clearInterval(cpPollInterval);
						}
					}
				});
			}, 5000);
		}

	});

})(jQuery);
