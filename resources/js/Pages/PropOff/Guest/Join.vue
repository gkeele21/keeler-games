<template>
    <Head title="Join Event" />

    <div class="min-h-screen bg-bg flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-surface rounded-2xl shadow-2xl p-8 border border-border">
            <!-- Event Info -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-warning/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <TrophyIcon class="w-10 h-10 text-warning" />
                </div>
                <h1 class="text-3xl font-bold text-body mb-2">
                    {{ invitation.event.name }}
                </h1>
                <p class="text-sm text-muted mt-2">
                    Event Date: {{ formatDate(invitation.event.event_date) }}
                </p>
            </div>

            <!-- Group Info -->
            <div class="bg-warning/10 border border-warning/30 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-2">
                    <UserGroupIcon class="w-5 h-5 text-warning" />
                    <p class="text-sm text-warning">
                        You're joining: <strong>{{ invitation.group.name }}</strong>
                    </p>
                </div>
            </div>

            <!-- "Is this you?" — shown when the entered name already exists in
                 this group. A name match is only ever a candidate: two real
                 people share a name often enough that merging them silently
                 would be worse than asking. -->
            <div v-if="verifyEntry" class="bg-surface-elevated border border-border rounded-lg p-5 mb-6">
                <h2 class="text-lg font-bold text-body mb-1">Is this you?</h2>
                <p v-if="verifyEntry.from_previous_group" class="text-sm text-muted mb-4">
                    Someone called "{{ verifyEntry.name }}" played with
                    {{ verifyEntry.previous_group_name }} last time. Pick up where you
                    left off and keep your history.
                </p>
                <p v-else class="text-sm text-muted mb-4">
                    Someone called "{{ verifyEntry.name }}" has already joined this group.
                </p>

                <div class="bg-surface border border-border rounded-lg p-4 mb-5">
                    <div class="font-semibold text-body">{{ verifyEntry.name }}</div>
                    <div class="text-sm text-muted">
                        <span v-if="verifyEntry.previous_event_name">
                            {{ verifyEntry.previous_event_name }} &middot;
                        </span>
                        {{ verifyEntry.answered }} of {{ verifyEntry.total }} questions answered
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="flex-1 bg-primary text-white py-3 px-4 rounded-lg font-semibold hover:bg-primary-hover disabled:opacity-50 transition"
                        @click="claimEntry"
                    >
                        Yes, that's me
                    </button>
                    <button
                        type="button"
                        class="flex-1 bg-surface-overlay text-body border border-border py-3 px-4 rounded-lg font-semibold hover:bg-surface transition"
                        @click="useDifferentName"
                    >
                        No, I'm someone else
                    </button>
                </div>
            </div>

            <!-- Registration Form -->
            <form v-if="!verifyEntry" @submit.prevent="submit">
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-body mb-2">
                        Enter Your Name <span class="text-danger">*</span>
                    </label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        class="w-full px-4 py-3 bg-surface-elevated border border-border text-body placeholder-muted rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        placeholder="Your name"
                        required
                        autofocus
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-body mb-2">
                        Email (Optional)
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="w-full px-4 py-3 bg-surface-elevated border border-border text-body placeholder-muted rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                        placeholder="your.email@example.com"
                    />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">
                        {{ form.errors.email }}
                    </p>
                </div>

                <!-- Password fields (show when email is entered) -->
                <template v-if="form.email">
                    <div class="mb-4">
                        <label for="password" class="block text-sm font-medium text-body mb-2">
                            Password (Optional)
                        </label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="w-full px-4 py-3 bg-surface-elevated border border-border text-body placeholder-muted rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            placeholder="Create a password"
                        />
                        <p class="mt-1 text-xs text-muted">
                            At least 8 characters. Create a password to login easily next time.
                        </p>
                        <p v-if="form.errors.password" class="mt-1 text-sm text-danger">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div v-if="form.password" class="mb-6">
                        <label for="password_confirmation" class="block text-sm font-medium text-body mb-2">
                            Confirm Password
                        </label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="w-full px-4 py-3 bg-surface-elevated border border-border text-body placeholder-muted rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"
                            placeholder="Confirm your password"
                        />
                        <p v-if="form.errors.password_confirmation" class="mt-1 text-sm text-danger">
                            {{ form.errors.password_confirmation }}
                        </p>
                    </div>
                </template>

                <div v-if="!form.email" class="mb-6"></div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full bg-primary text-white py-3 px-4 rounded-lg font-semibold hover:bg-primary/80 disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    <span v-if="form.processing">Joining...</span>
                    <span v-else>Join Event</span>
                </button>
            </form>

            <!-- Info Box -->
            <div class="mt-6 text-center text-sm text-muted">
                <p>No account required! Just enter your name to get started.</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { TrophyIcon, UserGroupIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    invitation: Object,
});

const page = usePage();

// Flashed by the controller when the entered name matches someone already in
// the group; clearing it drops back to the plain form.
const verifyEntry = computed(() => page.props.flash?.verifyEntry || null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    claim_user_id: null,
    allow_duplicate_name: false,
});

const claimEntry = () => {
    form.claim_user_id = verifyEntry.value?.user_id ?? null;
    form.post(route('propoff.guest.register', props.invitation.token));
};

// They're a different person who happens to share the name. Drop back to the
// form with the override set, so re-submitting doesn't just ask again.
const useDifferentName = () => {
    if (page.props.flash) {
        page.props.flash.verifyEntry = null;
    }
    form.claim_user_id = null;
    form.allow_duplicate_name = true;
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const submit = () => {
    form.post(route('propoff.guest.register', props.invitation.token));
};
</script>