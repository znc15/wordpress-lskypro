(function (window, document, $) {
    'use strict';

    $(function () {
        $('.lsky-keyword-rules').each(function () {
            var $wrap = $(this);
            var $tbody = $wrap.find('tbody');
            var template = $wrap.find('.lsky-rule-template').html() || '';
            var nextIndex = parseInt($wrap.data('nextIndex'), 10);

            if (Number.isNaN(nextIndex)) {
                nextIndex = 0;
            }

            function addRow() {
                if (!template) return;
                var html = template.replace(/__INDEX__/g, String(nextIndex));
                nextIndex += 1;
                $wrap.data('nextIndex', nextIndex);
                $tbody.find('.lsky-keyword-rules-empty').remove();
                $tbody.append(html);
            }

            $wrap.on('click', '.lsky-rule-add', function () {
                addRow();
            });

            $wrap.on('click', '.lsky-rule-remove', function () {
                $(this).closest('tr').remove();
                if ($tbody.find('tr').length === 0) {
                    $tbody.append('<tr class="lsky-keyword-rules-empty"><td colspan="4">暂无规则</td></tr>');
                }
            });
        });
    });
})(window, document, jQuery);
