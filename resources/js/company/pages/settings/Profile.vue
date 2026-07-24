<script setup>
import { ref } from 'vue'

const user = ref(
    JSON.parse(localStorage.getItem('user') || 'null')
)

console.log(user.value)
</script>

<template>

    <div class="page-wrapper">

        <div class="page-header d-print-none mb-2">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <h1 class="page-title">Настройки аккаунта</h1>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->
        <!-- END PAGE HEADER -->
        <!-- BEGIN PAGE BODY -->
        <main id="content" class="page-body">
            <div class="container-xl">
                <!-- BEGIN PAGE BODY -->
                <div class="card">
                    <div class="row g-0">
                        <div class="col-12 col-md-3 border-end">
                            <div class="card-body">
                                <h4 class="subheader">Business settings</h4>
                                <nav class="list-group list-group-transparent">
                                    <a href="./settings.html" class="list-group-item list-group-item-action d-flex align-items-center active">My Account</a>
                                    <a href="#" class="list-group-item list-group-item-action d-flex align-items-center">My Notifications</a>
                                    <a href="#" class="list-group-item list-group-item-action d-flex align-items-center">Connected Apps</a>
                                    <a href="./settings-plan.html" class="list-group-item list-group-item-action d-flex align-items-center">Plans</a>
                                    <a href="#" class="list-group-item list-group-item-action d-flex align-items-center">Billing & Invoices</a>
                                </nav>
                                <h4 class="subheader mt-4">Experience</h4>
                                <nav class="list-group list-group-transparent">
                                    <a href="#" class="list-group-item list-group-item-action">Give Feedback</a>
                                </nav>
                            </div>
                        </div>
                        <div class="col-12 col-md-9 d-flex flex-column">
                            <div class="card-body">
                                <h2 class="mb-4">My Account</h2>
                                    <div class="col-5">
                                        <label class="form-label" for="business-name">Name</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            id="business-name"
                                            name="name"
                                            :value="user?.name"
                                        />
                                    </div>

                                <h3 class="card-title mt-4">Email</h3>
                                <p class="card-subtitle">This contact will be shown to others publicly, so choose it carefully.</p>
                                <div>
                                    <div class="row g-2">
                                        <div class="col-4">
                                            <label for="email" class="form-label visually-hidden">Email</label>
                                            <input type="text" class="form-control" id="email" name="email" value="paweluna@howstuffworks.com" />
                                        </div>
                                        <div class="col-auto">
                                            <a href="#" class="btn btn-1"> Change </a>
                                        </div>
                                    </div>
                                </div>
                                <h3 class="card-title mt-4">Password</h3>
                                <p class="card-subtitle">You can set a permanent password if you don't want to use temporary login codes.</p>
                                <div>
                                    <a href="#" class="btn btn-1"> Set new password </a>
                                </div>

                            </div>
                            <div class="card-footer bg-transparent mt-auto">
                                <div class="btn-list justify-content-end">
                                    <a href="#" class="btn btn-1"> Cancel </a>
                                    <a href="#" class="btn btn-primary btn-2"> Submit </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- END PAGE BODY -->
            </div>
        </main>
        <!-- END PAGE BODY -->
        <!-- BEGIN FOOTER -->
        <!--  BEGIN FOOTER  -->
        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl">
                <div class="row text-center align-items-center flex-row-reverse">
                    <div class="col-lg-auto ms-lg-auto">
                        <nav aria-label="Footer">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item"><a href="https://docs.tabler.io" target="_blank" class="link-secondary" rel="noopener">Documentation</a></li>
                                <li class="list-inline-item"><a href="./license.html" class="link-secondary">License</a></li>
                                <li class="list-inline-item">
                                    <a href="https://github.com/tabler/tabler" target="_blank" class="link-secondary" rel="noopener">Source code</a>
                                </li>
                                <li class="list-inline-item">
                                    <a href="https://github.com/sponsors/codecalm" target="_blank" class="link-secondary" rel="noopener">
                                        <!-- Download SVG icon from http://tabler.io/icons/icon/heart -->
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="24"
                                            height="24"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                            focusable="false"
                                            class="icon text-pink icon-inline icon-4"
                                        >
                                            <path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
                                        </svg>
                                        Sponsor
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                        <ul class="list-inline list-inline-dots mb-0">
                            <li class="list-inline-item">
                                Copyright &copy; 2026
                                <a href="." class="link-secondary">Tabler</a>. All rights reserved.
                            </li>
                            <li class="list-inline-item">
                                <a href="./changelog.html" class="link-secondary" rel="noopener"> v1.4.0 </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </footer>
        <!--  END FOOTER  -->
        <!-- END FOOTER -->
    </div>
</template>
