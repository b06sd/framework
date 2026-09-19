<layout name="app">
    <fill slot="title">{{ title }}</fill>

    <section class="users">
        <h1>{{ title }}</h1>
        <for each="users" as="user">
            <article>{{ user.name }} ({{ user.email }})</article>
        <else>
            <p>No users</p>
        </for>
        <include name="partials/count" total="users|length" />
    </section>
</layout>
