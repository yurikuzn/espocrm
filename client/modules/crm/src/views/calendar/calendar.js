/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2023 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import View from 'view';
import moment from 'moment';
import * as FullCalendar from 'fullcalendar';

class CalendarView extends View {

    template = 'crm:calendar/calendar'

    eventAttributes = []
    colors = {}
    allDayScopeList = ['Task']
    scopeList = ['Meeting', 'Call', 'Task']
    header = true
    modeList = []
    fullCalendarModeList = [
        'month',
        'agendaWeek',
        'agendaDay',
        'basicWeek',
        'basicDay',
        'listWeek',
    ]
    defaultMode = 'agendaWeek'
    slotDuration = 30
    titleFormat = {
        month: 'MMMM YYYY',
        week: 'MMMM YYYY',
        day: 'dddd, MMMM D, YYYY',
    }

    /** @private */
    fetching = false

    modeViewMap = {
        month: 'dayGridMonth',
        agendaWeek: 'timeGridWeek',
        agendaDay: 'timeGridDay',
        basicWeek: 'dayGridWeek',
        basicDay: 'dayGridDay',
        listWeek: 'listWeek',
    }

    /** @type {FullCalendar.Calendar} */
    calendar

    events = {
        /** @this CalendarView */
        'click button[data-action="prev"]': function () {
            this.actionPrevious();
        },
        /** @this CalendarView */
        'click button[data-action="next"]': function () {
            this.actionNext();
        },
        /** @this CalendarView */
        'click button[data-action="today"]': function () {
            this.actionToday();
        },
        /** @this CalendarView */
        'click [data-action="mode"]': function (e) {
            let mode = $(e.currentTarget).data('mode');

            this.selectMode(mode);
        },
        /** @this CalendarView */
        'click [data-action="refresh"]': function () {
            this.actionRefresh();
        },
        /** @this CalendarView */
        'click [data-action="toggleScopeFilter"]': function (e) {
            let $target = $(e.currentTarget);
            let filterName = $target.data('name');

            let $check = $target.find('.filter-check-icon');

            if ($check.hasClass('hidden')) {
                $check.removeClass('hidden');
            } else {
                $check.addClass('hidden');
            }

            e.stopPropagation(e);

            this.toggleScopeFilter(filterName);
        },
    }

    data() {
        return {
            mode: this.mode,
            header: this.header,
            isCustomViewAvailable: this.isCustomViewAvailable,
            isCustomView: this.isCustomView,
            todayLabel: this.translate('Today', 'labels', 'Calendar'),
            todayLabelShort: this.translate('Today', 'labels', 'Calendar').slice(0, 2),
        };
    }

    setup() {
        this.wait(
            Espo.loader.requirePromise('lib!fullcalendar-moment')
        );

        this.date = this.options.date || null;
        this.mode = this.options.mode || this.defaultMode;
        this.header = ('header' in this.options) ? this.options.header : this.header;
        this.slotDuration = this.options.slotDuration || this.slotDuration;

        this.setupMode();

        this.$container = this.options.$container;

        this.colors = Espo.Utils
            .clone(this.getMetadata().get('clientDefs.Calendar.colors') || this.colors);
        this.modeList = this.getMetadata()
            .get('clientDefs.Calendar.modeList') || this.modeList;
        this.scopeList = this.getConfig()
            .get('calendarEntityList') || Espo.Utils.clone(this.scopeList);
        this.allDayScopeList = this.getMetadata()
            .get('clientDefs.Calendar.allDayScopeList') || this.allDayScopeList;

        this.colors = {...this.colors, ...this.getHelper().themeManager.getParam('calendarColors')};

        //this.scopeFilter = false;

        this.isCustomViewAvailable = this.getAcl().getPermissionLevel('userPermission') !== 'no';

        if (this.options.userId) {
            this.isCustomViewAvailable = false;
        }

        let scopeList = [];

        this.scopeList.forEach(scope => {
            if (this.getAcl().check(scope)) {
                scopeList.push(scope);
            }
        });

        this.scopeList = scopeList;

        if (this.header) {
            this.enabledScopeList = this.getStoredEnabledScopeList() || Espo.Utils.clone(this.scopeList);
        } else {
            this.enabledScopeList = this.options.enabledScopeList || Espo.Utils.clone(this.scopeList);
        }

        if (Object.prototype.toString.call(this.enabledScopeList) !== '[object Array]') {
            this.enabledScopeList = [];
        }

        this.enabledScopeList.forEach(item => {
            let color = this.getMetadata().get(['clientDefs', item, 'color']);

            if (color) {
                this.colors[item] = color;
            }
        });

        if (this.header) {
            this.createView('modeButtons', 'crm:views/calendar/mode-buttons', {
                el: this.getSelector() + ' .mode-buttons',
                isCustomViewAvailable: this.isCustomViewAvailable,
                modeList: this.modeList,
                scopeList: this.scopeList,
                mode: this.mode,
            });
        }
    }

    setupMode() {
        this.viewMode = this.mode;

        this.isCustomView = false;
        this.teamIdList = this.options.teamIdList || null;

        if (this.teamIdList && !this.teamIdList.length) {
            this.teamIdList = null;
        }

        if (~this.mode.indexOf('view-')) {
            this.viewId = this.mode.slice(5);
            this.isCustomView = true;

            let calendarViewDataList = this.getPreferences().get('calendarViewDataList') || [];

            calendarViewDataList.forEach(item => {
                if (item.id === this.viewId) {
                    this.viewMode = item.mode;
                    this.teamIdList = item.teamIdList;
                    this.viewName = item.name;
                }
            });
        }
    }

    isAgendaMode() {
        return this.mode.indexOf('agenda') === 0;
    }

    selectMode(mode) {
        if (~this.fullCalendarModeList.indexOf(mode) || mode.indexOf('view-') === 0) {
            let previousMode = this.mode;

            if (
                mode.indexOf('view-') === 0 ||
                mode.indexOf('view-') !== 0 && previousMode.indexOf('view-') === 0
            ) {
                this.trigger('change:mode', mode, true);

                return;
            }

            this.mode = mode;

            this.setupMode();

            if (this.isCustomView) {
                this.$el.find('button[data-action="editCustomView"]').removeClass('hidden');
            } else {
                this.$el.find('button[data-action="editCustomView"]').addClass('hidden');
            }

            this.$el.find('[data-action="mode"]').removeClass('active');
            this.$el.find('[data-mode="' + mode + '"]').addClass('active');

            this.calendar.changeView(this.modeViewMap[this.viewMode]);

            let toAgenda = previousMode.indexOf('agenda') !== 0 && mode.indexOf('agenda') === 0;
            let fromAgenda = previousMode.indexOf('agenda') === 0 && mode.indexOf('agenda') !== 0;

            if (
                toAgenda && !this.fetching ||
                fromAgenda && !this.fetching
            ) {
                this.calendar.refetchEvents();
            }

            this.updateDate();

            if (this.hasView('modeButtons')) {
                this.getView('modeButtons').mode = mode;
                this.getView('modeButtons').reRender();
            }
        }

        this.trigger('change:mode', mode);
    }

    toggleScopeFilter(name) {
        let index = this.enabledScopeList.indexOf(name);

        if (!~index) {
            this.enabledScopeList.push(name);
        } else {
            this.enabledScopeList.splice(index, 1);
        }

        this.storeEnabledScopeList(this.enabledScopeList);

        this.calendar.refetchEvents();
    }

    getStoredEnabledScopeList() {
        let key = 'calendarEnabledScopeList';

        return this.getStorage().get('state', key) || null;
    }

    storeEnabledScopeList(enabledScopeList) {
        let key = 'calendarEnabledScopeList';

        this.getStorage().set('state', key, enabledScopeList);
    }

    updateDate() {
        if (!this.header) {
            return;
        }

        if (this.isToday()) {
            this.$el.find('button[data-action="today"]').addClass('active');
        } else {
            this.$el.find('button[data-action="today"]').removeClass('active');
        }

        let title = this.getTitle();

        this.$el.find('.date-title h4 span').text(title);
    }

    isToday() {
        let view = this.calendar.view;

        let todayUnix = moment().unix();
        let startUnix = moment(view.activeStart).unix();
        let endUnix = moment(view.activeStart).unix();

        return startUnix <= todayUnix && todayUnix < endUnix;
    }

    getTitle() {
        let view = this.calendar.view;

        let map = {
            timeGridWeek: 'week',
            timeGridDay: 'day',
            dayGridWeek: 'week',
            dayGridDay: 'day',
            dayGridMonth: 'month',
        };

        let viewName = map[view.type] || view.type;

        let title;

        let format = this.titleFormat[viewName];

        if (viewName === 'week') {
            title = this.calendar.formatRange(view.currentStart, view.currentEnd, format);
        } else {
            title = moment(view.currentStart).format(format);
        }

        if (this.options.userId && this.options.userName) {
            title += ' (' + this.options.userName + ')';
        }

        title = this.getHelper().escapeString(title);

        return title;
    }

    convertToFcEvent(o) {
        let event = {
            title: o.name || '',
            scope: o.scope,
            id: o.scope + '-' + o.id,
            recordId: o.id,
            dateStart: o.dateStart,
            dateEnd: o.dateEnd,
            dateStartDate: o.dateStartDate,
            dateEndDate: o.dateEndDate,
            status: o.status,
            originalColor: o.color,
            display: 'block',
        };

        if (o.isWorkingRange) {
            event.display = 'inverse-background';
            event.groupId = 'nonWorking';
            event.color = this.colors['bg'];
        }

        if (this.teamIdList && o.userIdList) {
            event.userIdList = o.userIdList;
            event.userNameMap = o.userNameMap || {};

            event.userIdList = event.userIdList.sort((v1, v2) => {
                return (event.userNameMap[v1] || '').localeCompare(event.userNameMap[v2] || '');
            });
        }

        this.eventAttributes.forEach(attr => {
            event[attr] = o[attr];
        });

        let start;
        let end;

        if (o.dateStart) {
            if (!o.dateStartDate) {
                start = this.getDateTime().toMoment(o.dateStart);
            } else {
                start = this.getDateTime().toMomentDate(o.dateStartDate);
            }
        }

        if (o.dateEnd) {
            if (!o.dateEndDate) {
                end = this.getDateTime().toMoment(o.dateEnd);
            } else {
                end = this.getDateTime().toMomentDate(o.dateEndDate);
            }
        }

        if (end && start) {
            event.duration = end.unix() - start.unix();

            if (event.duration < 1800) {
                end = start.clone().add(30, 'm');
            }
        }

        if (start) {
            event.start = start.toDate();
        }

        if (end) {
            event.end = end.toDate();
        }

        event.allDay = false;

        if (!o.isWorkingRange) {
            this.handleAllDay(event);
            this.fillColor(event);
            this.handleStatus(event);
        }

        if (o.isWorkingRange && !this.isAgendaMode()) {
            event.allDay = true;
        }

        return event;
    }

    /**
     * @param {string} scope
     * @return {string[]}
     */
    getEventTypeCompletedStatusList(scope) {
        return this.getMetadata().get(['scopes', scope, 'completedStatusList']) || [];
    }

    /**
     * @param {string} scope
     * @return {string[]}
     */
    getEventTypeCanceledStatusList(scope) {
        return this.getMetadata().get(['scopes', scope, 'canceledStatusList']) || [];
    }

    fillColor(event) {
        let color = this.colors[event.scope];

        if (event.originalColor) {
            color = event.originalColor;
        }

        if (!color) {
            color = this.getColorFromScopeName(event.scope);
        }

        if (
            color &&
            (
                this.getEventTypeCompletedStatusList(event.scope).includes(event.status) ||
                this.getEventTypeCanceledStatusList(event.scope).includes(event.status)
            )
        ) {
            color = this.shadeColor(color, 0.4);
        }

        event.color = color;
    }

    handleStatus(event) {
        if (this.getEventTypeCanceledStatusList(event.scope).includes(event.status)) {
            event.className = ['event-canceled'];
        } else {
            event.className = [];
        }
    }

    shadeColor(color, percent) {
        if (color === 'transparent') {
            return color;
        }

        if (this.getThemeManager().getParam('isDark')) {
            percent *= -1;
        }

        let alpha = color.substring(7);

        let f = parseInt(color.slice(1, 7), 16),
            t = percent<0?0:255,
            p = percent < 0 ?percent *- 1 : percent,
            R = f >> 16,
            G = f >> 8&0x00FF,
            B = f&0x0000FF;

        return "#" + (
            0x1000000 + (
                Math.round((t - R) * p) + R) * 0x10000 +
            (Math.round((t - G) * p) + G) * 0x100 +
            (Math.round((t - B) * p) + B)
        ).toString(16).slice(1) + alpha;
    }

    handleAllDay(event, notInitial) {
        let start = event.start ? moment(event.start) : null;
        let end = event.start ? moment(event.end) : null;

        if (~this.allDayScopeList.indexOf(event.scope)) {
            event.allDay = event.allDayCopy = true;

            if (!notInitial) {
                if (end) {
                    start = end;

                    if (!event.dateEndDate && event.end.hours() === 0 && event.end.minutes() === 0) {
                        start.add(-1, 'days');
                    }
                }
            }

            return;
        }

        if (event.dateStartDate && event.dateEndDate) {
            event.allDay = true;
            event.allDayCopy = event.allDay;

            if (!notInitial) {
                end.add(1, 'days')
            }

            return;
        }

        if (!start || !end) {
            event.allDay = true;

            if (end) {
                start = end;
            }
        } else {
            if (
                (
                    start.format('d') !== end.format('d') &&
                    (end.hours() !== 0 || end.minutes() !== 0)
                ) ||
                (end.unix() - start.unix() >= 86400)
            ) {
                event.allDay = true;

                if (!notInitial) {
                    if (end.hours() !== 0 || end.minutes() !== 0) {
                        end.add(1, 'days');
                    }
                }
            } else {
                event.allDay = false;
            }
        }

        event.allDayCopy = event.allDay;

        if (start) {
            event.start = start.toDate();
        }

        if (end) {
            event.end = end.toDate();
        }
    }

    convertToFcEvents(list) {
        this.now = moment.tz(this.getDateTime().getTimeZone());

        let events = [];

        list.forEach(o => {
            let event = this.convertToFcEvent(o);

            events.push(event);
        });

        return events;
    }

    convertTime(d) {
        let format = this.getDateTime().internalDateTimeFormat;
        let timeZone = this.getDateTime().timeZone;
        let string = d.format(format);

        let m;

        if (timeZone) {
            m = moment.tz(string, format, timeZone).utc();
        } else {
            m = moment.utc(string, format);
        }

        return m.format(format) + ':00';
    }

    getCalculatedHeight() {
        if (this.$container && this.$container.length) {
            return this.$container.height();
        }

        return this.getHelper().calculateContentContainerHeight(this.$el.find('.calendar'));
    }

    adjustSize() {
        if (this.isRemoved()) {
            return;
        }

        let height = this.getCalculatedHeight();

        this.calendar.setOption('contentHeight', height);
    }

    afterRender() {
        if (this.options.containerSelector) {
            this.$container = $(this.options.containerSelector);
        }

        this.$calendar = this.$el.find('div.calendar');

        let slotDuration = '00:' + this.slotDuration + ':00';
        let timeFormat = this.getDateTime().timeFormat;

        let slotLabelFormat = timeFormat;

        if (~timeFormat.indexOf('a')) {
            slotLabelFormat = 'h(:mm)a';
        } else if (~timeFormat.indexOf('A')) {
            slotLabelFormat = 'h(:mm)A';
        }

        let options = {
            headerToolbar: false,
            slotLabelFormat: slotLabelFormat,
            eventTimeFormat: timeFormat,
            initialView: this.modeViewMap[this.viewMode],
            defaultRangeSeparator: ' – ',
            weekNumbers: true,
            weekNumberCalculation: 'ISO',
            editable: true,
            selectable: true,
            selectMirror: true,
            height: this.options.height || void 0,
            firstDay: this.getDateTime().weekStart,
            slotEventOverlap: true,
            slotDuration: slotDuration,
            snapDuration: this.slotDuration * 60 * 1000,
            timeZone: this.getDateTime().timeZone,
            longPressDelay: 300,
            //eventBackgroundColor: '#333',
            eventColor: this.colors[''],
            nowIndicator: true,
            allDayText: '',
            weekText: '',
            views: {
                week: {
                    dayHeaderFormat: 'ddd DD',
                },
                day: {
                    dayHeaderFormat: 'ddd DD',
                },
            },
            windowResize: () => {
                this.adjustSize();
            },
            select: (start, end) => {
                let dateStart = this.convertTime(start);
                let dateEnd = this.convertTime(end);
                let allDay = !start.hasTime();

                let dateEndDate = null;
                let dateStartDate = null;

                if (allDay) {
                    dateStartDate = start.format('YYYY-MM-DD');
                    dateEndDate = end.clone().add(-1, 'days').format('YYYY-MM-DD');
                }

                this.createEvent({
                    dateStart: dateStart,
                    dateEnd: dateEnd,
                    allDay: allDay,
                    dateStartDate: dateStartDate,
                    dateEndDate: dateEndDate,
                })

                this.calendar.unselect();
            },
            eventClick: info => {
                const event = info.event;

                let scope = event.extendedProps.scope;
                let recordId = event.extendedProps.recordId;

                let viewName = this.getMetadata().get(['clientDefs', scope, 'modalViews', 'detail']) ||
                    'views/modals/detail';

                Espo.Ui.notify(' ... ');

                this.createView('quickView', viewName, {
                    scope: scope,
                    id: recordId,
                    removeDisabled: false,
                }, view => {
                    view.render();
                    view.notify(false);

                    this.listenToOnce(view, 'after:destroy', model => {
                        this.removeModel(model);
                    });

                    this.listenTo(view, 'after:save', (model, o) => {
                        o = o || {};

                        if (!o.bypassClose) {
                            view.close();
                        }

                        this.updateModel(model);
                    });
                });
            },
            datesSet: () => {
                let date = this.getDateTime().fromIso(this.calendar.getDate().toISOString());
                let m = moment(this.calendar.getDate());

                this.date = date;

                this.trigger('view', m.format('YYYY-MM-DD'), this.mode);
            },
            events: (info, callback) => {

                let dateTimeFormat = this.getDateTime().internalDateTimeFormat;

                let from = moment.tz(info.startStr, info.timeZone);
                let to = moment.tz(info.endStr, info.timeZone);


                let fromStr = from.utc().format(dateTimeFormat);
                let toStr = to.utc().format(dateTimeFormat);

                this.fetchEvents(fromStr, toStr, callback);
            },
            eventDrop: info => {
                let event = info.event;
                let delta = info.delta;

                if (event.start.hasTime()) {
                    if (event.allDayCopy) {
                        info.revert();

                        return;
                    }
                } else {
                    if (!event.allDayCopy) {
                        info.revert();

                        return;
                    }
                }

                let attributes = {};

                if (event.dateStart) {
                    event.dateStart = this.convertTime(this.getDateTime().toMoment(event.dateStart).add(delta));
                    attributes.dateStart = event.dateStart;
                }

                if (event.dateEnd) {
                    event.dateEnd = this.convertTime(this.getDateTime().toMoment(event.dateEnd).add(delta));
                    attributes.dateEnd = event.dateEnd;
                }

                if (event.dateStartDate) {
                    let d = this.getDateTime().toMomentDate(event.dateStartDate).add(delta);

                    event.dateStartDate = d.format(this.getDateTime().internalDateFormat);
                    attributes.dateStartDate = event.dateStartDate;
                }

                if (event.dateEndDate) {
                    let d = this.getDateTime().toMomentDate(event.dateEndDate).add(delta);

                    event.dateEndDate = d.format(this.getDateTime().internalDateFormat);
                    attributes.dateEndDate = event.dateEndDate;
                }

                if (!event.end) {
                    if (!~this.allDayScopeList.indexOf(event.scope)) {
                        event.end = event.start.clone().add(event.duration, 's');
                    }
                }

                event.allDay = false;

                this.handleAllDay(event, true);
                this.fillColor(event);

                Espo.Ui.notify(this.translate('saving', 'messages'));

                this.getModelFactory().create(event.scope, model => {
                    model.id = event.recordId;

                    model
                        .save(attributes, {patch: true})
                        .then(() => {
                            Espo.Ui.notify(false);

                            this.$calendar.fullCalendar('updateEvent', event);
                        })
                        .catch(() => {
                            info.revert();
                        });
                });
            },
            eventResize: info => {
                let event = info.event;

                let attributes = {
                    dateEnd: this.convertTime(event.end),
                };

                event.dateEnd = attributes.dateEnd;
                event.duration = event.end.unix() - event.start.unix();

                this.fillColor(event);

                Espo.Ui.notify(this.translate('saving', 'messages'));

                this.getModelFactory().create(event.scope, model => {
                    model.id = event.recordId;

                    model.save(attributes, {patch: true})
                        .then(() => {
                            Espo.Ui.notify(false);

                            this.$calendar.fullCalendar('updateEvent', event);
                        })
                        .catch(() => {
                            info.revert();
                        });
                });
            },
        };

        if (this.teamIdList) {
            options.eventRender = arg => {
                const event = arg.event;

                let $content = $('<div>');

                if (!event.extendedProps.userIdList) {
                    // @todo Check.
                    return;
                }

                event.userIdList.forEach(userId => {
                    let userName = event.extendedProps.userNameMap[userId] || '';
                    let avatarHtml = this.getHelper().getAvatarHtml(userId, 'small', 13);

                    if (avatarHtml) {
                        avatarHtml += ' ';
                    }

                    let $div = $('<div>')
                        .addClass('user')
                        .append(avatarHtml)
                        .append(
                            $('<span>').text(userName)
                        );

                    $content.append($div);
                });

                return {html: $content.get(0).innerHTML};
            };
        }

        if (!this.options.height) {
            options.contentHeight = this.getCalculatedHeight();
        } else {
            options.aspectRatio = 1.62;
        }

        if (this.date) {
            options.initialDate = moment.utc(this.date).toDate();
        } else {
            this.$el.find('button[data-action="today"]').addClass('active');
        }

        setTimeout(() => {
            this.calendar = new FullCalendar.Calendar(this.$calendar.get(0), options);

            this.calendar.render();

            this.updateDate();

            if (this.$container && this.$container.length) {
                this.adjustSize();
            }
        }, 150);
    }

    /**
     * @param {{
     *   [allDay]: boolean,
     *   [dateStart]: string,
     *   [dateEnd]: string,
     *   [dateStartDate]: ?string,
     *   [dateEndDate]: ?string,
     * }} [values]
     */
    createEvent(values) {
        values = values || {};

        if (
            !values.dateStart &&
            this.date !== this.getDateTime().getToday() &&
            (this.mode === 'day' || this.mode === 'agendaDay')
        ) {
            values.allDay = true;
            values.dateStartDate = this.date;
            values.dateEndDate = this.date;
        }

        let attributes = {};

        if (this.options.userId) {
            attributes.assignedUserId = this.options.userId;
            attributes.assignedUserName = this.options.userName || this.options.userId;
        }

        Espo.Ui.notify(' ... ');

        this.createView('quickEdit', 'crm:views/calendar/modals/edit', {
            attributes: attributes,
            enabledScopeList: this.enabledScopeList,
            scopeList: this.scopeList,
            allDay: values.allDay,
            dateStartDate: values.dateStartDate,
            dateEndDate: values.dateEndDate,
            dateStart: values.dateStart,
            dateEnd: values.dateEnd,
        }, view => {
            view.render();

            Espo.Ui.notify(false);

            let added = false;

            this.listenTo(view, 'after:save', model => {
                if (!added) {
                    this.addModel(model);
                    added = true;

                    return;
                }

                this.updateModel(model);
            });
        });
    }

    fetchEvents(from, to, callback) {
        let url = 'Activities?from=' + from + '&to=' + to;

        if (this.options.userId) {
            url += '&userId=' + this.options.userId;
        }

        url += '&scopeList=' + encodeURIComponent(this.enabledScopeList.join(','));

        if (this.teamIdList && this.teamIdList.length) {
            url += '&teamIdList=' + encodeURIComponent(this.teamIdList.join(','));
        }

        let agenda = this.mode === 'agendaWeek' || this.mode === 'agendaDay';

        url += '&agenda=' + encodeURIComponent(agenda);

        Espo.Ajax.getRequest(url).then(data => {
            let events = this.convertToFcEvents(data);

            callback(events);

            Espo.Ui.notify(false);
        });

        this.fetching = true;

        setTimeout(() => this.fetching = false, 50)
    }

    addModel(model) {
        let d = model.getClonedAttributes();

        d.scope = model.name;

        let event = this.convertToFcEvent(d);

        this.calendar.addEvent(event);
    }

    updateModel(model) {
        let eventId = model.name + '-' + model.id;

        let event = this.calendar.getEventById(eventId);

        if (!event) {
            return;
        }

        let attributes = model.getClonedAttributes();

        attributes.scope = model.name;

        let data = this.convertToFcEvent(attributes);

        for (let key in data) {
            let value = data[key];

            if (key === 'start') {
                event.setStart(value);

                continue;
            }

            if (key === 'end') {
                event.setEnd(value);

                continue;
            }
            if (key === 'allDay') {
                event.setAllDay(value);

                continue;
            }

            event.setProp(key, value);
        }
    }

    removeModel(model) {
        let event = this.calendar.getEventById(model.name + '-' + model.id);

        if (!event) {
            return;
        }

        event.remove();
    }

    actionRefresh() {
        this.calendar.refetchEvents();
    }

    actionPrevious() {
        this.calendar.prev();

        this.updateDate();
    }

    actionNext() {
        this.calendar.next();

        this.updateDate();
    }

    getColorFromScopeName(scope) {
        let additionalColorList = this.getMetadata().get('clientDefs.Calendar.additionalColorList') || [];

        if (!additionalColorList.length) {
            return;
        }

        let colors = this.getMetadata().get('clientDefs.Calendar.colors') || {};

        let scopeList = this.getConfig().get('calendarEntityList') || [];

        let index = 0;
        let j = 0;

        for (let i = 0; i < scopeList.length; i++) {
            if (scopeList[i] in colors) {
                continue;
            }

            if (scopeList[i] === scope) {
                index = j;

                break;
            }

            j++;
        }

        index = index % additionalColorList.length;
        this.colors[scope] = additionalColorList[index];

        return this.colors[scope];
    }

    actionToday() {
        if (this.isToday()) {
            this.actionRefresh();

            return;
        }

        this.calendar.today();

        this.updateDate();
    }
}

export default CalendarView;
