<script setup>
import { ConnectionList } from '@statamic/cms';
import { Alert, Badge, Icon } from '@statamic/cms/ui';

defineEmits(['update:modelValue']);

defineProps({
    form: Object,
    modelValue: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    blueprint: Object,
    meta: { type: Object, default: () => ({}) },
    defaults: Object,
    configured: Boolean,
    endpoint: String,
});
</script>

<template>
    <Alert v-if="!configured" variant="warning" :heading="__('No CRM credentials')" :live="false">
        {{ __('Set BAMBOO_URL and BAMBOO_TOKEN in this site\'s environment before connecting a form.') }}
    </Alert>

    <ConnectionList
        v-else
        :model-value="modelValue"
        :errors
        :defaults
        :blueprint
        :meta
        name="bamboo-connection"
        :add-label="__('Connect to the CRM')"
        :description="__('Every submission becomes a ticket. Fields the CRM recognises land on the ticket and the patient; the rest are listed in a comment, in form order. One connection is enough — a second would send the same submission twice.')"
        :delete-heading="__('Disconnect')"
        :delete-description="__('Submissions will stop reaching the CRM. Nothing already sent is affected.')"
        :always-label="__('Always send')"
        :if-label="__('Send if...')"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <template #header>
            <Badge size="lg" pill color="white" class="gap-1 px-3 text-gray-950">
                <Icon name="media-ticket" class="me-1 size-3.5 opacity-100!" aria-hidden="true" />
                {{ endpoint }}
            </Badge>
        </template>
    </ConnectionList>
</template>
