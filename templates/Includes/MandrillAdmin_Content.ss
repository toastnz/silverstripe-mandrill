<div class="cms-content fill-height flexbox-area-grow cms-tabset center $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">

    <div class="cms-content-header north">
        <div class="cms-content-header-info vertical-align-items flexbox-area-grow">
            <div class="breadcrumbs-wrapper">
				<span class="cms-panel-link crumb last">
                    <% if $SectionTitle %>
                        $SectionTitle
                    <% else %>
                        <%t SilverStripe\Admin\ModelAdmin.Title 'Data Models' %>
                    <% end_if %>
                </span>
            </div>
        </div>

        <div class="cms-content-header-tabs cms-tabset-nav-primary ss-ui-tabs-nav">
            <% if $SearchForm %>
                <button id="filters-button" class="btn btn-secondary btn--icon-large font-icon-search no-text" title="<%t SilverStripe\CMS\Controllers\CMSPagesController.FILTER 'Filter' %>"></button>
            <% end_if %>
            <ul class="cms-tabset-nav-primary">
                <li><a href="#tab-messages"><%t MandrillAdmin.MessagesTab "Messages" %></a></li>
                <% if $CanConfigureWebhooks %>
                    <li><a href="#tab-hooks"><%t MandrillAdmin.HooksTab "Webhooks" %></a></li>
                <% end_if %>
            </ul>
        </div>
    </div>

    <div class="cms-content-fields center ui-widget-content cms-panel-padded fill-height flexbox-area-grow" data-layout-type="border">
        $Tools

        <div class="cms-content-view">

            <% if CurrentMessage %>
                <% with CurrentMessage %>
                    <div class="MandrillMessage">
                        <h3>$subject</h3>
                        <p class="infos">
                            <span class="state">$state</span> <%t MandrilAdmin.At "at" %>
                            <span class="date">$DateTime</span>
                            <% loop TagsList %>
                                <span class="tag">$Title</span>
                            <% end_loop %>
                        </p>
                        <% if html %>
                            <iframe src="/admin/mandrill/view_message/$_id" style="width:100%;height:400px;background:#fff;"></iframe>
                        <% else %>
                            <p class="message"><%t MandrilAdmin.ContentNotAvailable "The content of the email is not available anymore. Only recently sent messages are available." %></p>
                        <% end_if %>
                        <h4>$opens <%t MandrilAdmin.Opens "Opens" %></h4>
                        <% loop OpensList %>
                            <div class="meta">
                                $date, $IpLink, $location, $ua
                            </div>
                        <% end_loop %>
                        <h4>$clicks <%t MandrilAdmin.Clicks "Clicks" %></h4>
                        <% loop ClicksList %>
                            <div class="meta">
                                $date, $IpLink, <a href="$url" target="_blank">$url</a>, $location, $ua
                            </div>
                        <% end_loop %>
                    </div>
                <% end_with %>
            <% end_if %>

            <div id="tab-messages">
                $SearchForm
                $ListForm
            </div>

            <% if $CanConfigureWebhooks %>
                <div id="tab-hooks">
                    <% if $WebhookInstalled %>
                        $UninstallHookForm

                        <% with $WebhookDetails %>
                            <h3><%t MandrillAdmin.HooksDetailsTitle "Hooks details" %></h3>
                            <table class="ss-gridfield-table">
                                <tbody class="ss-gridfield-items">
                                <tr class="ss-gridfield-item even">
                                    <td>id</td>
                                    <td>$id</td>
                                </tr>
                                <tr class="ss-gridfield-item odd">
                                    <td>url</td>
                                    <td>$url</td>
                                </tr>
                                <tr class="ss-gridfield-item even">
                                    <td>description</td>
                                    <td>$description</td>
                                </tr>
                                <tr class="ss-gridfield-item odd">
                                    <td>created_at</td>
                                    <td>$created_at</td>
                                </tr>
                                <tr class="ss-gridfield-item even">
                                    <td>batches_sent</td>
                                    <td>$batches_sent</td>
                                </tr>
                                <tr class="ss-gridfield-item odd">
                                    <td>events_sent</td>
                                    <td>$events_sent</td>
                                </tr>
                                <tr class="ss-gridfield-item even">
                                    <td>auth_key</td>
                                    <td>$auth_key</td>
                                </tr>
                                </tbody>
                            </table>
                        <% end_with %>

                    <% else %>
                        $InstallHookForm
                    <% end_if %>
                </div>
            <% end_if %>
        </div>
    </div>

</div>

