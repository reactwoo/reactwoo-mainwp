ReactWoo Support Portal

This repository contains the ReactWoo Support Portal WordPress plugin scaffold
and a companion client connector plugin.
The portal plugin provides database tables and REST endpoints for
subscription-bound site onboarding, token verification, and client identity
synchronization. The connector plugin (./connector) handles enrollment and
signed heartbeats from client sites. The maintenance bridge plugin
(./maint-bridge) registers maintenance hub tables and REST endpoints for
enrollment and lifecycle actions.
It also registers subscription lifecycle hooks (including payment-failed grace
period handling) and scheduled cleanup for expired enrollment tokens.
