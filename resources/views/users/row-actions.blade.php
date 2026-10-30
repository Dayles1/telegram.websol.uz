{{-- Edit and delete of one user ($u), in the table row and in the phone card --}}
@can('update', $u)
    <x-ui.button :href="route('users.edit', $u)" size="icon-sm" variant="ghost" :title="__('common.edit')"><x-ui.icon name="edit" /></x-ui.button>
@endcan
@can('delete', $u)
    <form method="POST" action="{{ route('users.destroy', $u) }}" x-data
          @submit.prevent="$store.confirm.ask({ title: @js(__('users.delete_title')), body: @js(__('users.delete_body', ['name' => $u->name])), action: @js(__('common.delete')) }).then((ok) => ok && $el.submit())">
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" size="icon-sm" variant="danger-ghost" :title="__('common.delete')"><x-ui.icon name="trash" /></x-ui.button>
    </form>
@endcan
