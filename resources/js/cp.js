import BambooConnector from './components/BambooConnector.vue';

Statamic.booting(() => {
    Statamic.$components.register('bamboo-connector', BambooConnector);
});
