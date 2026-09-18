<script setup>
import AuthenticatedLayout from '@/Layouts/PropOffLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/Components/Base/PageHeader.vue';
import Button from '@/Components/Base/Button.vue';
import Badge from '@/Components/Base/Badge.vue';
import Card from '@/Components/Base/Card.vue';
import Modal from '@/Components/Base/Modal.vue';
import Confirm from '@/Components/Feedback/Confirm.vue';
import TextField from '@/Components/Form/TextField.vue';
import Icon from '@/Components/Base/Icon.vue';
import Toast from '@/Components/Feedback/Toast.vue';
import { useFlashToast } from '@/composables/useFlashToast';

const { showErrorToast, showSuccessToast, errorMessage, successMessage, showFlashMessages } = useFlashToast();

const props = defineProps({
    group: {
        type: Object,
        required: true,
    },
    members: {
        type: Array,
        required: true,
    },
});

const showAddGuestModal = ref(false);
const guestName = ref('');

// Confirmation dialog state
const confirmDialog = ref({
    show: false,
    title: '',
    message: '',
    messageHtml: '',
    variant: 'default',
    icon: '',
    action: null,
});

const showConfirmDialog = (title, message, action, variant = 'default', icon = '', messageHtml = '') => {
    confirmDialog.value = {
        show: true,
        title,
        message: messageHtml ? '' : message,
        messageHtml,
        variant,
        icon,
        action,
    };
};

const handleConfirm = () => {
    if (confirmDialog.value.action) {
        confirmDialog.value.action();
    }
};

const promoteToCaptain = (member) => {
    showConfirmDialog(
        'Promote to Captain?',
        `Are you sure you want to promote ${member.name} to captain?`,
        () => {
            router.post(route('propoff.groups.members.promote', { group: props.group.id, user: member.id }), {}, {
                preserveScroll: true,
                onSuccess: (page) => {
                    confirmDialog.value.show = false;
                    showFlashMessages(page.props);
                },
            });
        },
        'info',
        'star'
    );
};

const demoteFromCaptain = (member) => {
    showConfirmDialog(
        'Demote Captain?',
        `Are you sure you want to demote ${member.name} to a regular member?`,
        () => {
            router.post(route('propoff.groups.members.demote', { group: props.group.id, user: member.id }), {}, {
                preserveScroll: true,
                onSuccess: (page) => {
                    confirmDialog.value.show = false;
                    showFlashMessages(page.props);
                },
            });
        },
        'warning',
        'arrow-down'
    );
};

const removeMember = (member) => {
    const hasAnswers = member.entries_count > 0;
    const title = hasAnswers ? 'Remove Member & Delete Answers?' : 'Remove Member?';
    const messageHtml = hasAnswers
        ? `Are you sure you want to remove ${member.name} from the group?<br><br>They have answered <span class="text-primary font-semibold">${member.entries_count}</span> ${member.entries_count === 1 ? 'question' : 'questions'}. This will delete all their answers and scores.`
        : `Are you sure you want to remove ${member.name} from the group?`;

    showConfirmDialog(
        title,
        '',
        () => {
            router.delete(route('propoff.groups.members.remove', { group: props.group.id, user: member.id }), {
                preserveScroll: true,
                onSuccess: (page) => {
                    confirmDialog.value.show = false;
                    showFlashMessages(page.props);
                },
                onError: () => {
                    confirmDialog.value.show = false;
                },
            });
        },
        'danger',
        'user-minus',
        messageHtml
    );
};

const separateMember = (member) => {
    showConfirmDialog(
        'Not the same person?',
        '',
        () => {
            router.post(route('propoff.groups.members.separate', { group: props.group.id, user: member.id }), {}, {
                preserveScroll: true,
                onSuccess: (page) => {
                    confirmDialog.value.show = false;
                    showFlashMessages(page.props);
                },
                onError: () => {
                    confirmDialog.value.show = false;
                },
            });
        },
        'warning',
        'user-minus',
        `Use this if ${member.name} confirmed someone else's entry when they joined.<br><br>`
            + `Their answers in this group move to a separate person with the same name. `
            + `Nothing changes for the original ${member.name} — they keep their earlier events and scores.`,
    );
};

const addGuest = () => {
    if (!guestName.value.trim()) {
        return;
    }

    router.post(route('propoff.groups.members.addGuest', props.group.id), {
        name: guestName.value.trim(),
    }, {
        preserveScroll: true,
        onSuccess: (page) => {
            guestName.value = '';
            showAddGuestModal.value = false;
            showFlashMessages(page.props);
        },
    });
};

const submitEntryFor = (userId) => {
    router.get(route('propoff.play.game', { code: props.group.join_code, for_user: userId }));
};

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<template>
    <Head title="Manage Members" />

    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Manage Members"
                subtitle="Manage members and permissions for your group"
                :crumbs="[
                    { label: 'Home', href: route('propoff.play.hub', { code: group.join_code }) },
                    { label: 'Members' }
                ]"
            >
                <template #actions>
                    <Button
                        variant="primary"
                        size="sm"
                        icon="user-plus"
                        @click="showAddGuestModal = true"
                    >
                        Add Group Member
                    </Button>
                </template>
            </PageHeader>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Members List -->
                <div class="bg-surface overflow-hidden shadow-sm sm:rounded-lg border border-border">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-body mb-4">Members ({{ members.length }})</h3>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-border">
                                <thead class="bg-surface-elevated">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Name
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Email
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Role
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Joined
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Entries
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-subtle uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface divide-y divide-border">
                                    <tr v-for="member in members" :key="member.id" class="hover:bg-surface-elevated">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-body">{{ member.name }}</span>
                                                <Badge v-if="member.is_guest" variant="info-soft" size="sm">
                                                    Guest
                                                </Badge>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-muted">
                                            {{ member.email || 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <Badge
                                                v-if="member.is_captain"
                                                variant="warning-soft"
                                                icon="star"
                                            >
                                                Captain
                                            </Badge>
                                            <Badge v-else variant="default">
                                                Member
                                            </Badge>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-muted">
                                            {{ formatDate(member.joined_at) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-muted">
                                            {{ member.entries_count }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                                            <Button
                                                variant="ghost"
                                                size="xs"
                                                @click="submitEntryFor(member.id)"
                                            >
                                                Submit Entry
                                            </Button>
                                            <Button
                                                v-if="!member.is_captain"
                                                variant="ghost"
                                                size="xs"
                                                @click="promoteToCaptain(member)"
                                            >
                                                Promote
                                            </Button>
                                            <Button
                                                v-if="member.is_captain"
                                                variant="ghost"
                                                size="xs"
                                                class="!text-warning"
                                                @click="demoteFromCaptain(member)"
                                            >
                                                Demote
                                            </Button>
                                            <Button
                                                v-if="!member.is_captain"
                                                variant="ghost"
                                                size="xs"
                                                class="!text-warning"
                                                @click="separateMember(member)"
                                            >
                                                Not them
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="xs"
                                                class="!text-danger"
                                                @click="removeMember(member)"
                                            >
                                                Remove
                                            </Button>
                                        </td>
                                    </tr>
                                    <tr v-if="members.length === 0">
                                        <td colspan="6" class="px-6 py-12 text-center text-muted">
                                            No members found
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Info Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <Card border-color="warning">
                        <div class="flex items-start gap-3">
                            <Icon name="star" class="text-warning mt-0.5" />
                            <div>
                                <h4 class="font-semibold text-body mb-1">About Captains</h4>
                                <p class="text-sm text-muted">
                                    Captains have full control over the group, including managing questions, grading, and member management.
                                    You can promote multiple members to captain.
                                </p>
                            </div>
                        </div>
                    </Card>
                    <Card border-color="danger">
                        <div class="flex items-start gap-3">
                            <Icon name="triangle-exclamation" class="text-danger mt-0.5" />
                            <div>
                                <h4 class="font-semibold text-body mb-1">Warning</h4>
                                <p class="text-sm text-muted">
                                    Removing a member will also delete all their entries and scores. You cannot demote the last captain.
                                </p>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Add Guest Modal -->
                <Modal :show="showAddGuestModal" max-width="sm" @close="showAddGuestModal = false">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-body mb-4">Add Guest User</h3>
                        <TextField
                            v-model="guestName"
                            label="Guest Name"
                            placeholder="Enter guest name"
                            @keyup.enter="addGuest"
                        />
                        <div class="flex justify-end gap-3 mt-6">
                            <Button
                                variant="outline"
                                @click="showAddGuestModal = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="success"
                                :disabled="!guestName.trim()"
                                @click="addGuest"
                            >
                                Add Guest
                            </Button>
                        </div>
                    </div>
                </Modal>

                <!-- Confirmation Dialog -->
                <Confirm
                    :show="confirmDialog.show"
                    :title="confirmDialog.title"
                    :message="confirmDialog.message"
                    :message-html="confirmDialog.messageHtml"
                    :variant="confirmDialog.variant"
                    :icon="confirmDialog.icon"
                    @confirm="handleConfirm"
                    @cancel="confirmDialog.show = false"
                    @close="confirmDialog.show = false"
                />

                <!-- Flash Messages -->
                <Toast
                    :show="showErrorToast"
                    :message="errorMessage"
                    variant="error"
                    position="top-right"
                    @close="showErrorToast = false"
                />
                <Toast
                    :show="showSuccessToast"
                    :message="successMessage"
                    variant="success"
                    position="top-right"
                    @close="showSuccessToast = false"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
