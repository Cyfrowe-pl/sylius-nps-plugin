/**
 * The follow-up question depends on the score, and the customer can change their mind on the page.
 * Both wordings are rendered into data attributes by the server, so swapping them needs no request
 * - and with this script blocked the page still shows the wording for the score already saved.
 *
 * Plain browser script on purpose: a shop should not have to add a build step to get this.
 */
(function () {
    'use strict';

    function swapOnChange() {
        var question = document.querySelector('[data-question-low][data-question-high][data-promoter-from]');

        if (null === question) {
            return;
        }

        var promoterFrom = parseInt(question.dataset.promoterFrom, 10);
        var scores = document.querySelectorAll('input[type="radio"][name$="[score]"]');

        Array.prototype.forEach.call(scores, function (input) {
            input.addEventListener('change', function () {
                var score = parseInt(input.value, 10);

                question.textContent = score >= promoterFrom
                    ? question.dataset.questionHigh
                    : question.dataset.questionLow;
            });
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', swapOnChange);
    } else {
        swapOnChange();
    }
})();
