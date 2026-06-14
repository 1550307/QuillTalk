(function (global) {
    'use strict';

    function createInitialState() {
        return {
            version: 1,
            board: [],
            turn: 'w',
            winnerColor: null,
            resultCode: null,
            resultLabel: null,
            lastMove: null,
            moveCount: 0,
            phase: 'waiting',
            prompt: '',
            referencePrompt: '',
            promptStartedAt: null,
            promptEndsAt: null,
            roundSeconds: 60,
            referenceImage: null,
            whiteSubmission: null,
            blackSubmission: null,
            whiteScore: null,
            blackScore: null
        };
    }

    function normalizeImagePayload(value) {
        if (!value || typeof value !== 'object') {
            return null;
        }

        var url = String(value.url || '').trim();
        if (!url) {
            return null;
        }

        return {
            kind: 'image',
            url: url,
            name: String(value.name || 'Sketchoff image').trim() || 'Sketchoff image',
            mime: String(value.mime || 'image/png').trim() || 'image/png',
            size: Math.max(0, Number(value.size || 0) || 0),
            caption: String(value.caption || '').trim(),
            submittedAt: String(value.submittedAt || '').trim() || null
        };
    }

    function normalizeScore(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        return Math.max(0, Math.min(100, Number(value) || 0));
    }

    function normalizeState(state) {
        var base = createInitialState();
        if (!state || typeof state !== 'object') {
            return base;
        }

        var phase = String(state.phase || base.phase).trim().toLowerCase();
        if (['waiting', 'drawing', 'revealed'].indexOf(phase) === -1) {
            phase = 'waiting';
        }

        return {
            version: 1,
            board: [],
            turn: state.turn === 'b' ? 'b' : 'w',
            winnerColor: state.winnerColor === 'b' ? 'b' : (state.winnerColor === 'w' ? 'w' : null),
            resultCode: String(state.resultCode || '').trim() || null,
            resultLabel: String(state.resultLabel || '').trim() || null,
            lastMove: null,
            moveCount: Math.max(0, Number(state.moveCount || 0) || 0),
            phase: phase,
            prompt: String(state.prompt || '').trim(),
            referencePrompt: String(state.referencePrompt || '').trim(),
            promptStartedAt: String(state.promptStartedAt || '').trim() || null,
            promptEndsAt: String(state.promptEndsAt || '').trim() || null,
            roundSeconds: Math.max(15, Math.min(300, Number(state.roundSeconds || 60) || 60)),
            referenceImage: normalizeImagePayload(state.referenceImage),
            whiteSubmission: normalizeImagePayload(state.whiteSubmission),
            blackSubmission: normalizeImagePayload(state.blackSubmission),
            whiteScore: normalizeScore(state.whiteScore),
            blackScore: normalizeScore(state.blackScore)
        };
    }

    global.QuillTalkSketchoff = {
        createInitialState: createInitialState,
        normalizeState: normalizeState,
        getBoardDimensions: function () {
            return { rows: 1, cols: 1 };
        },
        coordsToSquare: function () {
            return 'a1';
        },
        squareToCoords: function () {
            return { row: 0, col: 0 };
        },
        getLegalMovesForColor: function () {
            return [];
        },
        getLegalMovesForSquare: function () {
            return [];
        },
        getPieceGlyph: function () {
            return '';
        }
    };
})(window);
