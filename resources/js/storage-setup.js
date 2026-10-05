(() => {
    'use strict';

    const chooseButton = document.querySelector('#chooseStorageButton');
    const retryButton = document.querySelector('#retryStorageButton');
    const status = document.querySelector('#storageSetupStatus');
    const actionButtons = [chooseButton, retryButton].filter(Boolean);

    const runAction = async (bindingProvider, waitingMessage) => {
        actionButtons.forEach((button) => {
            button.disabled = true;
            button.classList.add('is-loading');
        });
        status.classList.remove('is-error');
        status.textContent = waitingMessage;

        let fatal = false;
        let transitioning = false;

        try {
            const binding = bindingProvider();

            if (typeof binding !== 'function') {
                throw new Error('Storage setup is not available. Restart RelayDeck and try again.');
            }

            const result = await binding();

            if (!result || result.ok !== true) {
                const failure = new Error(result?.error?.message || 'Unable to use the selected location.');
                failure.retryable = result?.error?.details?.retryable !== false;
                throw failure;
            }

            if (result.data === null) {
                status.textContent = 'Nothing selected. Choose a location to continue.';
                return;
            }

            status.textContent = 'Storage is ready. Opening your workspace…';
            chooseButton.textContent = 'Opening RelayDeck…';
            transitioning = true;

            window.setTimeout(() => {
                status.classList.add('is-error');
                status.textContent = 'The workspace did not open. Restart RelayDeck to continue.';
                chooseButton.textContent = 'Restart RelayDeck to continue';
            }, 8000);
        } catch (error) {
            status.classList.add('is-error');
            status.textContent = error?.message || 'Unable to configure storage.';

            if (error?.retryable === false) {
                chooseButton.textContent = 'Restart RelayDeck to continue';
                fatal = true;
            }
        } finally {
            actionButtons.forEach((button) => button.classList.remove('is-loading'));

            if (!fatal && !transitioning) {
                actionButtons.forEach((button) => {
                    button.disabled = false;
                });
            }
        }
    };

    chooseButton.addEventListener('click', () => runAction(
        () => window.api?.setup?.chooseStorage,
        'Waiting for selection…',
    ));

    retryButton?.addEventListener('click', () => runAction(
        () => window.api?.setup?.retryStorage,
        'Checking the saved database…',
    ));
})();
